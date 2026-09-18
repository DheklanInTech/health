<?php

declare(strict_types=1);

namespace Olisa;

use RuntimeException;

/**
 * Outbound email: MIME assembly plus a choice of transport.
 *
 * Two kinds of message go out from here. The coordinator notification, which
 * deliberately contains no health answers — only a reference and a dashboard
 * link, because a notification that leaks is a notification that should not
 * have existed. And participant messages composed in the dashboard, which are
 * whatever the coordinator wrote, rendered through EmailTemplate.
 *
 * Transport is SMTP when configured and PHP's mail() otherwise. mail() hands
 * the message to the local MTA, whose deliverability on shared hosting is
 * mediocre and whose failures are invisible — it returns true as long as the
 * message was accepted for delivery, which says nothing about whether it
 * arrived. Configure SMTP before sending a campaign to real applicants.
 */
final class Mailer
{
    /**
     * Shared transport for a batch. Opening a session per message is what gets
     * a sending address rate-limited by its own provider.
     */
    private static ?Smtp $transport = null;

    public static function usesSmtp(): bool
    {
        return Config::str('mail.smtp_host') !== '';
    }

    /** @return array{ok: bool, transport: string, error?: string} */
    public static function checkTransport(): array
    {
        if (!self::usesSmtp()) {
            return [
                'ok'        => function_exists('mail'),
                'transport' => 'mail()',
                'error'     => function_exists('mail') ? null : 'PHP mail() is disabled on this server.',
            ];
        }

        try {
            $smtp = Smtp::fromConfig();
            $smtp->connect();
            $smtp->quit();
            return ['ok' => true, 'transport' => 'SMTP ' . Config::str('mail.smtp_host')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'transport' => 'SMTP ' . Config::str('mail.smtp_host'), 'error' => $e->getMessage()];
        }
    }

    /** Opens (or reuses) the batch transport. Returns null when using mail(). */
    public static function openBatch(): ?Smtp
    {
        if (!self::usesSmtp()) {
            return null;
        }
        if (self::$transport === null || !self::$transport->isConnected()) {
            self::$transport = Smtp::fromConfig();
            self::$transport->connect();
        }
        return self::$transport;
    }

    public static function closeBatch(): void
    {
        self::$transport?->quit();
        self::$transport = null;
    }

    /**
     * Sends one message. Throws rather than returning false — the caller
     * records the reason against the recipient row, and a silent failure in a
     * queue is a queue nobody can debug.
     *
     * @param array{email: string, name?: string} $to
     * @param array<string, string> $options subject, html, text, reply_to, list_unsubscribe
     * @throws RuntimeException
     */
    public static function send(array $to, array $options, ?Smtp $smtp = null): void
    {
        $address = trim((string) ($to['email'] ?? ''));
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Not a valid email address.');
        }

        $subject = self::oneLine((string) ($options['subject'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException('The message has no subject.');
        }

        $html = (string) ($options['html'] ?? '');
        $text = (string) ($options['text'] ?? '');
        if ($html === '' && $text === '') {
            throw new RuntimeException('The message has no content.');
        }

        $fromAddress = self::fromAddress();
        $boundary    = 'tp-' . bin2hex(random_bytes(12));

        $headers = [
            'Date'         => gmdate('D, j M Y H:i:s') . ' +0000',
            'Message-ID'   => '<' . bin2hex(random_bytes(12)) . '@' . self::messageIdDomain() . '>',
            'From'         => self::formatAddress($fromAddress, self::fromName()),
            'To'           => self::formatAddress($address, (string) ($to['name'] ?? '')),
            'Subject'      => self::encodeHeader($subject),
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        ];

        $replyTo = trim((string) ($options['reply_to'] ?? Config::str('mail.reply_to')));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = self::formatAddress($replyTo, self::fromName());
        }

        // Puts the unsubscribe control in the client's own chrome, where people
        // actually look for it. Gmail and Outlook both surface it, and a
        // recipient who uses it is one who does not press "report spam".
        $unsubscribe = self::oneLine((string) ($options['list_unsubscribe'] ?? ''));
        if ($unsubscribe !== '') {
            $headers['List-Unsubscribe'] = $unsubscribe;
            // RFC 8058 one-click is only valid against an https URI that
            // accepts a POST. Advertising it beside a bare mailto: is a
            // promise this application cannot keep.
            if (stripos($unsubscribe, '<https://') !== false) {
                $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
            }
        }

        if (!empty($options['auto_generated'])) {
            $headers['Auto-Submitted'] = 'auto-generated';
            $headers['X-Auto-Response-Suppress'] = 'All';
        }

        $body = self::multipartBody($boundary, $text, $html);

        if ($smtp !== null || self::usesSmtp()) {
            $smtp ??= self::openBatch();
            if ($smtp === null) {
                throw new RuntimeException('SMTP transport unavailable.');
            }
            $smtp->send($fromAddress, [$address], self::headerBlock($headers), $body);
            return;
        }

        // mail() supplies its own To and Subject, so they are removed from the
        // header block — passing them twice produces duplicate headers, which
        // several filters treat as a forgery signal.
        $mailHeaders = $headers;
        unset($mailHeaders['To'], $mailHeaders['Subject']);

        $sent = @mail(
            $address,
            self::encodeHeader($subject),
            $body,
            self::headerBlock($mailHeaders),
            '-f' . $fromAddress
        );

        if ($sent !== true) {
            throw new RuntimeException('The server rejected the message (PHP mail() returned false).');
        }
    }

    /**
     * Renders and sends one templated participant message.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $recipient
     * @throws RuntimeException
     */
    public static function sendTemplated(array $message, array $recipient, ?Smtp $smtp = null): void
    {
        $rendered = EmailTemplate::render($message, $recipient);

        $unsubscribe = '';
        $contact = Config::str('mail.reply_to') ?: Config::str('mail.to');
        if ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $unsubscribe = '<mailto:' . $contact . '?subject=' . rawurlencode('Unsubscribe ' . (string) ($recipient['reference'] ?? '')) . '>';
        }

        self::send(
            ['email' => (string) $recipient['email'], 'name' => (string) ($recipient['name'] ?? '')],
            [
                'subject'          => $rendered['subject'],
                'html'             => $rendered['html'],
                'text'             => $rendered['text'],
                'list_unsubscribe' => $unsubscribe,
            ],
            $smtp
        );
    }

    // -------------------------------------------------------- notification

    /**
     * Optional "new application" notification to the coordinator inbox.
     * Carries the reference and a dashboard link only — never the answers.
     */
    public static function notifyNewSubmission(string $applicationId, string $trial): void
    {
        if (!Config::bool('mail.notify')) {
            return;
        }

        $to = Config::str('mail.to');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $trialName = Trials::name($trial);
        $base      = rtrim(Config::str('app.url'), '/');
        $link      = $base . Config::str('app.admin_base', '/admin') . '/submissions.php?q=' . urlencode($applicationId);

        $text = implode("\r\n", [
            'A new screening application has been received.',
            '',
            'Reference: ' . $applicationId,
            'Trial:     ' . $trialName,
            'Received:  ' . Util::now() . ' UTC',
            '',
            'Open it in the dashboard:',
            $link,
            '',
            'This notification deliberately contains no health information.',
        ]);

        try {
            self::send(['email' => $to], [
                'subject'        => sprintf('[%s] New application — %s', Config::str('app.name'), $trialName),
                'text'           => $text,
                'auto_generated' => true,
            ]);
        } catch (\Throwable $e) {
            // A failed notification must never fail the applicant's submission.
            error_log('Notification email failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------ internals

    private static function multipartBody(string $boundary, string $text, string $html): string
    {
        $parts = [];

        // Plain text first: RFC 2046 says the last alternative is the most
        // faithful, so a client that understands both picks the HTML.
        $parts[] = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text !== '' ? $text : strip_tags($html)), 76, "\r\n");

        if ($html !== '') {
            $parts[] = "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                // base64 rather than quoted-printable: the template has long
                // inline style attributes, and an encoder that wraps them at
                // the wrong place breaks the markup.
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html), 76, "\r\n");
        }

        return implode("\r\n", $parts) . "\r\n--{$boundary}--\r\n";
    }

    /** @param array<string, string> $headers */
    private static function headerBlock(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = self::oneLine($name) . ': ' . self::oneLine($value);
        }
        return implode("\r\n", $lines);
    }

    /** Header injection guard — anything that could start a new header goes. */
    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00]+/', ' ', $value) ?? '');
    }

    /** RFC 2047 for non-ASCII header values; plain ASCII passes through. */
    private static function encodeHeader(string $value): string
    {
        $value = self::oneLine($value);
        if (preg_match('//u', $value) && mb_check_encoding($value, 'ASCII')) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function formatAddress(string $address, string $name = ''): string
    {
        $address = self::oneLine($address);
        $name    = self::oneLine($name);
        if ($name === '') {
            return $address;
        }
        // A display name holding a comma or a quote must be encoded, not just
        // wrapped — otherwise it reads as two addresses.
        $encoded = mb_check_encoding($name, 'ASCII') && !preg_match('/["\\\\,:;<>@]/', $name)
            ? '"' . $name . '"'
            : '=?UTF-8?B?' . base64_encode($name) . '?=';
        return $encoded . ' <' . $address . '>';
    }

    private static function fromName(): string
    {
        // An empty configured value must fall back too, not just a missing one.
        return Config::str('mail.from_name') ?: Config::str('app.name');
    }

    private static function fromAddress(): string
    {
        $from = Config::str('mail.from');
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $from;
        }
        $host = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        return 'no-reply@' . $host;
    }

    /** The right-hand side of the Message-ID, which filters expect to match From. */
    private static function messageIdDomain(): string
    {
        $from = self::fromAddress();
        $at = strrpos($from, '@');
        return $at === false ? 'localhost' : substr($from, $at + 1);
    }
}
