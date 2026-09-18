<?php

declare(strict_types=1);

namespace Olisa;

use RuntimeException;

/**
 * A small authenticated SMTP client, written against the socket rather than
 * pulled in as a dependency.
 *
 * The rest of this application deploys by copying files onto cPanel hosting
 * with no Composer and no build step, and adding a vendor directory for one
 * class would end that. So: ~200 lines covering ESMTP, STARTTLS, AUTH
 * LOGIN/PLAIN and pipelined recipients, which is the whole of what sending a
 * campaign through a cPanel mailbox needs.
 *
 * The connection is deliberately reusable. A bulk send opens one session and
 * walks the queue through it — reconnecting per message is what gets a sending
 * address rate-limited, and on 500 recipients it is also forty times slower.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;

    private bool $esmtp = false;

    /** @var array<int, string> Capabilities advertised in the EHLO response. */
    private array $capabilities = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $username = '',
        private readonly string $password = '',
        /** '', 'tls' (STARTTLS, port 587) or 'ssl' (implicit, port 465) */
        private readonly string $encryption = 'tls',
        private readonly int $timeout = 20,
        private readonly bool $verifyPeer = true,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            Config::str('mail.smtp_host'),
            Config::int('mail.smtp_port', 587),
            Config::str('mail.smtp_user'),
            Config::str('mail.smtp_password'),
            strtolower(Config::str('mail.smtp_encryption', 'tls')),
            Config::int('mail.smtp_timeout', 20),
            Config::get('mail.smtp_verify_peer', true) !== false,
        );
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket) && !feof($this->socket);
    }

    /** @throws RuntimeException on any failure to reach a usable session */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }
        if ($this->host === '') {
            throw new RuntimeException('No SMTP host configured.');
        }

        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => $this->tlsOptions()]);

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('Could not connect to %s:%d — %s', $this->host, $this->port, $error !== '' ? $error : 'timed out'));
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        $this->expect([220], 'greeting');
        $this->hello();

        if ($this->encryption === 'tls') {
            $this->startTls();
        }

        if ($this->username !== '') {
            $this->authenticate();
        }
    }

    /** @return array<string, mixed> */
    private function tlsOptions(): array
    {
        return [
            'verify_peer'       => $this->verifyPeer,
            'verify_peer_name'  => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'SNI_enabled'       => true,
            // TLS 1.0/1.1 are dead; a host that still needs them needs fixing,
            // not accommodating.
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
    }

    private function hello(): void
    {
        $client = $this->clientName();

        $response = $this->command('EHLO ' . $client, [250], 'EHLO', false);
        if ($response === null) {
            // Pre-ESMTP server, or one that dislikes our name. HELO offers no
            // AUTH and no STARTTLS, so anything requiring them fails below.
            $this->command('HELO ' . $client, [250], 'HELO');
            $this->esmtp = false;
            $this->capabilities = [];
            return;
        }

        $this->esmtp = true;
        $this->capabilities = [];
        foreach (preg_split('/\r?\n/', $response) ?: [] as $line) {
            // "250-AUTH LOGIN PLAIN" -> "AUTH LOGIN PLAIN"
            $this->capabilities[] = strtoupper(trim(substr($line, 4)));
        }
    }

    /** The EHLO name. A bare hostname is fine; an empty or spoofed one is not. */
    private function clientName(): string
    {
        $configured = Config::str('mail.smtp_helo');
        if ($configured !== '') {
            return $configured;
        }
        $host = (string) ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $host)[0]) ?? '';
        return $host !== '' ? $host : (gethostname() ?: 'localhost');
    }

    private function startTls(): void
    {
        if (!$this->supports('STARTTLS')) {
            throw new RuntimeException('The server does not offer STARTTLS. Set mail.smtp_encryption to "ssl" or "".');
        }

        $this->command('STARTTLS', [220], 'STARTTLS');

        $socket = $this->socket;
        if (!is_resource($socket)) {
            throw new RuntimeException('Connection lost during STARTTLS.');
        }

        $ok = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );
        if ($ok !== true) {
            throw new RuntimeException('TLS negotiation failed. Check the certificate on ' . $this->host . '.');
        }

        // Capabilities before and after TLS are allowed to differ, and AUTH is
        // usually only advertised afterwards — so ask again.
        $this->hello();
    }

    private function authenticate(): void
    {
        if (!$this->esmtp) {
            throw new RuntimeException('The server does not support authentication.');
        }

        if ($this->supports('AUTH') && str_contains($this->capability('AUTH'), 'PLAIN')) {
            $credentials = base64_encode("\0" . $this->username . "\0" . $this->password);
            $this->command('AUTH PLAIN ' . $credentials, [235], 'authentication');
            return;
        }

        if ($this->supports('AUTH') && str_contains($this->capability('AUTH'), 'LOGIN')) {
            $this->command('AUTH LOGIN', [334], 'authentication');
            $this->command(base64_encode($this->username), [334], 'username');
            $this->command(base64_encode($this->password), [235], 'password');
            return;
        }

        throw new RuntimeException('No supported authentication method (need PLAIN or LOGIN).');
    }

    /**
     * Sends one message over the open session.
     *
     * @param array<int, string> $recipients envelope recipients
     * @throws RuntimeException if the server rejects the envelope or the data
     */
    public function send(string $from, array $recipients, string $headers, string $body): void
    {
        $this->connect();

        $this->command('MAIL FROM:<' . $this->sanitiseAddress($from) . '>', [250], 'MAIL FROM');

        foreach ($recipients as $recipient) {
            $this->command('RCPT TO:<' . $this->sanitiseAddress($recipient) . '>', [250, 251], 'RCPT TO');
        }

        $this->command('DATA', [354], 'DATA');

        $message = rtrim($headers, "\r\n") . "\r\n\r\n" . $body;
        $this->write($this->dotStuff($message) . "\r\n.\r\n");
        $this->expect([250], 'message body');
    }

    /** RSET between messages, so a rejected recipient cannot poison the next. */
    public function reset(): void
    {
        if ($this->isConnected()) {
            try {
                $this->command('RSET', [250], 'RSET');
            } catch (RuntimeException) {
                $this->close();
            }
        }
    }

    public function quit(): void
    {
        if ($this->isConnected()) {
            try {
                $this->write("QUIT\r\n");
                $this->readResponse();
            } catch (RuntimeException) {
                // Nothing useful left to do — the session is ending regardless.
            }
        }
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->capabilities = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    // ------------------------------------------------------------- internals

    private function supports(string $capability): bool
    {
        return $this->capability($capability) !== '';
    }

    private function capability(string $name): string
    {
        foreach ($this->capabilities as $line) {
            if ($line === $name || str_starts_with($line, $name . ' ')) {
                return $line;
            }
        }
        return '';
    }

    /**
     * @param array<int, int> $expected
     * @return string|null the full response, or null when $throw is false and
     *                     the server answered with an unexpected code
     */
    private function command(string $command, array $expected, string $stage, bool $throw = true): ?string
    {
        $this->write($command . "\r\n");
        return $this->expect($expected, $stage, $throw);
    }

    /**
     * @param array<int, int> $expected
     * @return string|null
     */
    private function expect(array $expected, string $stage, bool $throw = true): ?string
    {
        $response = $this->readResponse();
        $code = (int) substr($response, 0, 3);

        if (in_array($code, $expected, true)) {
            return $response;
        }
        if (!$throw) {
            return null;
        }

        throw new RuntimeException(sprintf('SMTP %s failed: %s', $stage, $this->firstLine($response)));
    }

    /** Reads a complete reply, following the "250-" continuation convention. */
    private function readResponse(): string
    {
        $socket = $this->socket;
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connection is closed.');
        }

        $response = '';
        while (true) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new RuntimeException(
                    !empty($meta['timed_out']) ? 'SMTP server timed out.' : 'SMTP connection dropped.'
                );
            }
            $response .= $line;

            // A space in the fourth position marks the final line.
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return $response;
    }

    private function write(string $data): void
    {
        $socket = $this->socket;
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connection is closed.');
        }
        if (@fwrite($socket, $data) === false) {
            throw new RuntimeException('Writing to the SMTP connection failed.');
        }
    }

    /** RFC 5321 §4.5.2 — a line of a single dot would otherwise end the DATA. */
    private function dotStuff(string $message): string
    {
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }

    /** Envelope addresses must never carry CR, LF or angle brackets. */
    private function sanitiseAddress(string $address): string
    {
        $clean = preg_replace('/[\r\n<>\x00]/', '', trim($address)) ?? '';
        if ($clean === '' || filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid email address in the envelope.');
        }
        return $clean;
    }

    private function firstLine(string $response): string
    {
        return trim(preg_split('/\r?\n/', trim($response))[0] ?? $response);
    }
}
