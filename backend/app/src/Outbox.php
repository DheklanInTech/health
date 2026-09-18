<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Queue and worker for dashboard-composed email.
 *
 * Nothing is sent inside the request that composes it. A campaign to four
 * hundred applicants over authenticated SMTP takes minutes, and a PHP request
 * on shared hosting is killed at thirty seconds — so the compose page writes
 * one row per recipient and then drains as much of the queue as it safely can
 * before handing the rest to the next run or to cron.
 *
 * That also means a message is never sent twice and never half-sent invisibly:
 * every address carries its own status, attempt count and failure reason.
 */
final class Outbox
{
    /** Audiences a message can be addressed to. */
    public const AUDIENCES = [
        'single'    => 'One address',
        'manual'    => 'A list of addresses',
        'filter'    => 'Everyone matching a filter',
        'selection' => 'Selected applications',
    ];

    /** Safety rail: a mistyped filter should not be able to write to everyone. */
    private const MAX_RECIPIENTS = 5000;

    /**
     * Sending pause between messages, in microseconds. Most cPanel mailboxes
     * cap hourly volume, and a batch that arrives as fast as the socket allows
     * is the pattern that trips the cap.
     */
    private const THROTTLE_US = 120000;

    /**
     * Resolves the addresses a queue request is aimed at.
     *
     * @param array<string, mixed> $request
     * @return array{recipients: array<int, array<string, mixed>>, suppressed: int, invalid: array<int, string>, meta: array<string, mixed>}
     */
    public static function resolveAudience(string $audience, array $request): array
    {
        $invalid = [];
        $meta    = [];
        $rows    = [];

        if ($audience === 'single' || $audience === 'manual') {
            $raw = (string) ($request['addresses'] ?? '');
            // Commas, semicolons, newlines — people paste from all three.
            foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $candidate) {
                $candidate = mb_strtolower(trim($candidate));
                if ($candidate === '') {
                    continue;
                }
                if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $invalid[] = $candidate;
                    continue;
                }
                $rows[$candidate] = [
                    'submission_id' => null,
                    'email'         => $candidate,
                    'name'          => trim((string) ($request['name'] ?? '')),
                    'reference'     => '',
                    'trial'         => '',
                ];
            }
            $rows = array_values($rows);

            // An address that belongs to a known applicant should still carry
            // their reference, so the footer and {{reference}} work.
            $known = Repo::submissionsByEmails(array_column($rows, 'email'));
            foreach ($rows as $index => $row) {
                $match = $known[$row['email']] ?? null;
                if ($match !== null) {
                    $rows[$index]['submission_id'] = (string) $match['id'];
                    $rows[$index]['reference']     = (string) $match['application_id'];
                    $rows[$index]['trial']         = (string) $match['trial'];
                }
            }

            $meta = ['addresses' => count($rows)];
        } elseif ($audience === 'selection') {
            $ids  = array_values(array_filter((array) ($request['ids'] ?? []), 'is_string'));
            $rows = Repo::audienceByIds($ids);
            $meta = ['selected' => count($ids)];
        } else {
            $filters = [
                'trial'   => (string) ($request['trial'] ?? 'all'),
                'status'  => (string) ($request['status'] ?? 'all'),
                'verdict' => (string) ($request['verdict'] ?? 'all'),
                'from'    => (string) ($request['from'] ?? ''),
                'to'      => (string) ($request['to'] ?? ''),
            ];
            $rows = Repo::audience($filters);
            $meta = array_filter($filters, static fn(string $v): bool => $v !== '' && $v !== 'all');
        }

        // The suppression list is applied here, not at send time, so the
        // confirmation on screen is the truth about who will be written to.
        $suppressedCount = 0;
        if ($rows !== []) {
            $suppressed = Repo::suppressedAmong(array_column($rows, 'email'));
            if ($suppressed !== []) {
                $before = count($rows);
                $rows = array_values(array_filter(
                    $rows,
                    static fn(array $r): bool => !in_array($r['email'], $suppressed, true)
                ));
                $suppressedCount = $before - count($rows);
            }
        }

        return ['recipients' => $rows, 'suppressed' => $suppressedCount, 'invalid' => $invalid, 'meta' => $meta];
    }

    /**
     * Validates a composed message. Returns the list of problems, empty when
     * it is safe to queue.
     *
     * @param array<string, mixed> $message
     * @return array<int, string>
     */
    public static function validate(array $message, int $recipientCount): array
    {
        $errors = [];

        $subject = trim((string) ($message['subject'] ?? ''));
        $body    = trim((string) ($message['body'] ?? ''));

        if ($subject === '') {
            $errors[] = 'Write a subject line.';
        } elseif (mb_strlen($subject) > 200) {
            $errors[] = 'The subject is over 200 characters — most inboxes cut it off around 60.';
        }

        if ($body === '') {
            $errors[] = 'Write the message.';
        } elseif (mb_strlen($body) > 20000) {
            $errors[] = 'The message is too long. Keep it under 20,000 characters.';
        }

        $ctaLabel = trim((string) ($message['cta_label'] ?? ''));
        $ctaUrl   = trim((string) ($message['cta_url'] ?? ''));
        if ($ctaUrl !== '' && !preg_match('#^https?://#i', $ctaUrl)) {
            $errors[] = 'The button link must start with http:// or https://.';
        } elseif ($ctaUrl !== '' && filter_var($ctaUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'The button link is not a valid URL.';
        } elseif ($ctaLabel !== '' && $ctaUrl === '') {
            $errors[] = 'The button has a label but no link.';
        }

        if ($recipientCount === 0) {
            $errors[] = 'No one matches — nothing would be sent.';
        } elseif ($recipientCount > self::MAX_RECIPIENTS) {
            $errors[] = 'That is ' . number_format($recipientCount) . ' recipients, over the '
                . number_format(self::MAX_RECIPIENTS) . ' limit. Narrow the filter.';
        }

        if (Config::str('mail.from') === '') {
            $errors[] = 'No sending address is configured. Set mail.from in config.php first.';
        }

        return $errors;
    }

    /**
     * Writes the message and its recipients, then returns the message id.
     *
     * @param array<string, mixed> $message
     * @param array<int, array<string, mixed>> $recipients
     */
    public static function queue(array $message, array $recipients, string $actorEmail): string
    {
        $id = Repo::createMessage($message, $actorEmail);
        Repo::addRecipients($id, $recipients);
        return $id;
    }

    /**
     * Sends as much of one message as fits in the time available.
     *
     * @param float|null $deadline unix timestamp to stop by; null means "cron,
     *                             take your time"
     * @return array{sent: int, failed: int, remaining: int, aborted: string|null}
     */
    public static function drain(string $messageId, int $batchSize = 25, ?float $deadline = null): array
    {
        $message = Repo::getMessage($messageId);
        if ($message === null || in_array((string) $message['status'], ['cancelled'], true)) {
            return ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'aborted' => 'Message is not sendable.'];
        }

        $sent = 0;
        $failed = 0;
        $aborted = null;
        $smtp = null;

        try {
            $smtp = Mailer::openBatch();
        } catch (\Throwable $e) {
            // A transport that will not open fails the whole batch, not each
            // address in turn — otherwise one bad password burns every retry.
            Repo::refreshMessageCounts($messageId, false);
            return [
                'sent'      => 0,
                'failed'    => 0,
                'remaining' => Repo::pendingRecipientCount($messageId),
                'aborted'   => $e->getMessage(),
            ];
        }

        while (true) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $aborted = 'Time limit reached — the rest is still queued.';
                break;
            }

            $claim = Util::uuid();
            $batch = Repo::claimRecipients($messageId, $batchSize, $claim);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $recipient) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    // Whatever is left of this claim goes back to pending.
                    Repo::releaseClaim($claim);
                    $aborted = 'Time limit reached — the rest is still queued.';
                    break 2;
                }

                try {
                    Mailer::sendTemplated($message, $recipient, $smtp);
                    Repo::markRecipientSent((string) $recipient['id']);
                    $sent++;
                } catch (\Throwable $e) {
                    if (self::isTransportFailure($e)) {
                        // The connection died, not the address. Hand the rest
                        // of the claim back rather than blaming the recipients.
                        Repo::releaseClaim($claim);
                        Mailer::closeBatch();
                        $aborted = $e->getMessage();
                        break 2;
                    }
                    Repo::markRecipientFailed((string) $recipient['id'], $e->getMessage());
                    $failed++;
                }

                if (self::THROTTLE_US > 0) {
                    usleep(self::THROTTLE_US);
                }
            }
        }

        if ($smtp !== null) {
            Mailer::closeBatch();
        }

        $counts = Repo::refreshMessageCounts($messageId);

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $counts['pending'], 'aborted' => $aborted];
    }

    /**
     * A refused recipient is that recipient's problem; a dropped socket or a
     * rejected login is everyone's. Only the second kind should stop a batch.
     */
    private static function isTransportFailure(\Throwable $e): bool
    {
        $message = $e->getMessage();
        foreach (['connection', 'timed out', 'TLS', 'authentication', 'Could not connect', 'closed'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The recipient the compose preview is rendered against: the first real
     * one, or a representative stand-in when the audience is still empty.
     *
     * The stand-in matters. With blank values the merge fields collapse and the
     * author previews a message that nobody will ever receive — worse, one that
     * looks broken where it will not be.
     */
    public static function previewRecipient(array $recipients, string $fallbackEmail): array
    {
        $first = $recipients[0] ?? [];

        $trial = (string) ($first['trial'] ?? '');
        if ($trial === '') {
            $trial = Trials::slugs()[0] ?? '';
        }

        return [
            'email'     => (string) ($first['email'] ?? '') ?: $fallbackEmail,
            'name'      => (string) ($first['name'] ?? ''),
            'reference' => (string) ($first['reference'] ?? '') ?: 'TP-WL-EXAMPLE',
            'trial'     => $trial,
        ];
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'sent'      => 'good',
            'failed'    => 'serious',
            'sending'   => 'info',
            'queued'    => 'accent',
            'cancelled' => 'warn',
            default     => '',
        };
    }
}
