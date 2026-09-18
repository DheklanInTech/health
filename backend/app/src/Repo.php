<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Data access. Every SQL statement in the application lives here, and every
 * value reaching one is a bound parameter.
 *
 * The two exceptions to binding are LIMIT/OFFSET and the ORDER BY column: MySQL
 * cannot bind either. Both are cast to int or matched against an allow-list
 * before they touch the string, so neither is attacker-controlled.
 */
final class Repo
{
    public const STATUSES = ['new', 'reviewing', 'eligible', 'ineligible', 'contacted', 'enrolled', 'withdrawn'];

    public const STATUS_LABELS = [
        'new'        => 'New',
        'reviewing'  => 'Reviewing',
        'eligible'   => 'Eligible',
        'ineligible' => 'Ineligible',
        'contacted'  => 'Contacted',
        'enrolled'   => 'Enrolled',
        'withdrawn'  => 'Withdrawn',
    ];

    public const VERDICTS = ['eligible', 'review', 'excluded'];

    private const SORT_COLUMNS = [
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'email'      => 'email',
        'age'        => 'age',
        'status'     => 'status',
        'trial'      => 'trial',
    ];

    // ------------------------------------------------------------- submissions

    /**
     * @param array<string, string> $answers
     * @param array{verdict: string, flags: array, bmi: float|null} $eligibility
     */
    public static function createSubmission(
        string $trial,
        array $answers,
        array $eligibility,
        string $ipHash,
        string $userAgent
    ): array {
        $id  = Util::uuid();
        $now = Util::now();

        // Collisions are vanishingly unlikely (32^6) but the column is UNIQUE,
        // so retry rather than 500 on the visitor.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $applicationId = Util::applicationId(Trials::idPrefix($trial));
            try {
                Db::run(
                    'INSERT INTO submissions
                        (id, application_id, trial, status, email, phone, age, sex,
                         answers, eligibility, verdict, bmi, ip_hash, user_agent, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $id,
                        $applicationId,
                        $trial,
                        'new',
                        $answers['email'] ?? '',
                        $answers['phone'] ?? '',
                        isset($answers['age']) && $answers['age'] !== '' ? (int) $answers['age'] : null,
                        $answers['sex'] ?? '',
                        json_encode($answers, JSON_UNESCAPED_UNICODE),
                        json_encode($eligibility, JSON_UNESCAPED_UNICODE),
                        $eligibility['verdict'],
                        $eligibility['bmi'],
                        $ipHash,
                        $userAgent,
                        $now,
                        $now,
                    ]
                );
                return ['id' => $id, 'application_id' => $applicationId];
            } catch (\PDOException $e) {
                if (!self::isDuplicateKey($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not allocate an application ID');
    }

    private static function isDuplicateKey(\PDOException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE');
    }

    /**
     * Builds the shared WHERE clause for the list, export and stats queries.
     *
     * @param array<string, string> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function where(array $filters): array
    {
        $clauses = [];
        $params  = [];

        $trial = $filters['trial'] ?? '';
        if ($trial !== '' && $trial !== 'all' && Trials::exists($trial)) {
            $clauses[] = 'trial = ?';
            $params[]  = $trial;
        }

        $status = $filters['status'] ?? '';
        if ($status !== '' && $status !== 'all' && in_array($status, self::STATUSES, true)) {
            $clauses[] = 'status = ?';
            $params[]  = $status;
        }

        $verdict = $filters['verdict'] ?? '';
        if ($verdict !== '' && $verdict !== 'all' && in_array($verdict, self::VERDICTS, true)) {
            $clauses[] = 'verdict = ?';
            $params[]  = $verdict;
        }

        $from = $filters['from'] ?? '';
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $clauses[] = 'created_at >= ?';
            $params[]  = $from . ' 00:00:00';
        }

        $to = $filters['to'] ?? '';
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $clauses[] = 'created_at <= ?';
            $params[]  = $to . ' 23:59:59';
        }

        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $clauses[] = '(application_id LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, string> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public static function listSubmissions(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::where($filters);

        $total = (int) Db::scalar("SELECT COUNT(*) FROM submissions {$where}", $params);

        $perPage = max(10, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        // Allow-listed column + int-cast paging: never attacker-controlled.
        $sortKey = $filters['sort'] ?? 'created_at';
        $column  = self::SORT_COLUMNS[$sortKey] ?? 'created_at';
        $dir     = strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $rows = Db::all(
            "SELECT id, application_id, trial, status, email, phone, age, sex, verdict, bmi,
                    eligibility, created_at, updated_at
             FROM submissions
             {$where}
             ORDER BY {$column} {$dir}, id ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$row) {
            $row['eligibility'] = json_decode((string) $row['eligibility'], true) ?: [];
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /**
     * Unpaginated, for CSV export. Streamed by the caller.
     *
     * @param array<string, string> $filters
     * @return \PDOStatement
     */
    public static function streamSubmissions(array $filters): \PDOStatement
    {
        [$where, $params] = self::where($filters);
        return Db::run("SELECT * FROM submissions {$where} ORDER BY created_at DESC", $params);
    }

    public static function getSubmission(string $id): ?array
    {
        $row = Db::one('SELECT * FROM submissions WHERE id = ? OR application_id = ? LIMIT 1', [$id, $id]);
        if ($row === null) {
            return null;
        }
        $row['answers']     = json_decode((string) $row['answers'], true) ?: [];
        $row['eligibility'] = json_decode((string) $row['eligibility'], true) ?: [];
        $row['notes']       = self::notes((string) $row['id']);
        return $row;
    }

    /** Neighbouring records, so a reviewer can work through a filtered queue. */
    public static function adjacentIds(string $createdAt, array $filters): array
    {
        [$where, $params] = self::where($filters);
        $prefix = $where === '' ? 'WHERE' : $where . ' AND';

        $next = Db::scalar(
            "SELECT id FROM submissions {$prefix} created_at < ? ORDER BY created_at DESC LIMIT 1",
            [...$params, $createdAt]
        );
        $prev = Db::scalar(
            "SELECT id FROM submissions {$prefix} created_at > ? ORDER BY created_at ASC LIMIT 1",
            [...$params, $createdAt]
        );

        return ['prev' => $prev, 'next' => $next];
    }

    public static function updateStatus(string $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        Db::run('UPDATE submissions SET status = ?, updated_at = ? WHERE id = ?', [$status, Util::now(), $id]);
        return true;
    }

    /** @param array<int, string> $ids */
    public static function bulkUpdateStatus(array $ids, string $status): int
    {
        $ids = array_values(array_filter($ids, 'is_string'));
        if ($ids === [] || !in_array($status, self::STATUSES, true)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::run(
            "UPDATE submissions SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
            [$status, Util::now(), ...$ids]
        );
        return $stmt->rowCount();
    }

    public static function deleteSubmission(string $id): bool
    {
        // submission_notes cascades in MySQL; SQLite needs foreign_keys ON,
        // which Db sets. Delete explicitly so behaviour is identical either way.
        Db::run('DELETE FROM submission_notes WHERE submission_id = ?', [$id]);
        return Db::run('DELETE FROM submissions WHERE id = ?', [$id])->rowCount() > 0;
    }

    public static function countRecentByIp(string $ipHash, int $seconds): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM submissions WHERE ip_hash = ? AND created_at >= ?',
            [$ipHash, gmdate('Y-m-d H:i:s', time() - $seconds)]
        );
    }

    /** Guards against a double-click or a refresh creating two identical records. */
    public static function recentDuplicate(string $trial, string $email, int $seconds = 300): ?array
    {
        return Db::one(
            'SELECT id, application_id FROM submissions
             WHERE trial = ? AND email = ? AND created_at >= ?
             ORDER BY created_at DESC LIMIT 1',
            [$trial, $email, gmdate('Y-m-d H:i:s', time() - $seconds)]
        );
    }

    // ------------------------------------------------------------------- notes

    public static function notes(string $submissionId): array
    {
        return Db::all(
            'SELECT * FROM submission_notes WHERE submission_id = ? ORDER BY created_at ASC',
            [$submissionId]
        );
    }

    public static function addNote(string $submissionId, string $authorEmail, string $body): void
    {
        Db::run(
            'INSERT INTO submission_notes (id, submission_id, author_email, body, created_at) VALUES (?,?,?,?,?)',
            [Util::uuid(), $submissionId, $authorEmail, mb_substr($body, 0, 5000), Util::now()]
        );
        Db::run('UPDATE submissions SET updated_at = ? WHERE id = ?', [Util::now(), $submissionId]);
    }

    public static function deleteNote(string $noteId): bool
    {
        return Db::run('DELETE FROM submission_notes WHERE id = ?', [$noteId])->rowCount() > 0;
    }

    // ------------------------------------------------------------------- stats

    /** Everything the overview page needs, in a handful of aggregate queries. */
    public static function stats(int $days = 30): array
    {
        $since = Util::daysAgo($days);

        $byStatus = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM submissions GROUP BY status') as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }

        $byTrial = [];
        foreach (Db::all('SELECT trial, COUNT(*) AS n FROM submissions GROUP BY trial') as $row) {
            $byTrial[(string) $row['trial']] = (int) $row['n'];
        }

        $byVerdict = [];
        foreach (Db::all('SELECT verdict, COUNT(*) AS n FROM submissions GROUP BY verdict') as $row) {
            $byVerdict[(string) $row['verdict']] = (int) $row['n'];
        }

        // Daily counts per trial for the stacked time series.
        $dateExpr = Db::isSqlite() ? "substr(created_at, 1, 10)" : "DATE(created_at)";
        $daily = Db::all(
            "SELECT {$dateExpr} AS day, trial, COUNT(*) AS n
             FROM submissions WHERE created_at >= ?
             GROUP BY day, trial ORDER BY day ASC",
            [$since]
        );

        return [
            'total'       => (int) Db::scalar('SELECT COUNT(*) FROM submissions'),
            'today'       => (int) Db::scalar('SELECT COUNT(*) FROM submissions WHERE created_at >= ?', [Util::today() . ' 00:00:00']),
            'last7'       => (int) Db::scalar('SELECT COUNT(*) FROM submissions WHERE created_at >= ?', [Util::daysAgo(7)]),
            'last30'      => (int) Db::scalar('SELECT COUNT(*) FROM submissions WHERE created_at >= ?', [Util::daysAgo(30)]),
            'prev7'       => (int) Db::scalar('SELECT COUNT(*) FROM submissions WHERE created_at >= ? AND created_at < ?', [Util::daysAgo(14), Util::daysAgo(7)]),
            'unreviewed'  => (int) Db::scalar('SELECT COUNT(*) FROM submissions WHERE status = ?', ['new']),
            'byStatus'    => $byStatus,
            'byTrial'     => $byTrial,
            'byVerdict'   => $byVerdict,
            'daily'       => $daily,
            'days'        => $days,
        ];
    }

    /** Most recent submissions, for the overview list. */
    public static function recent(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        return Db::all(
            "SELECT id, application_id, trial, status, email, verdict, created_at
             FROM submissions ORDER BY created_at DESC LIMIT {$limit}"
        );
    }

    // ------------------------------------------------------------------- users

    public static function listUsers(): array
    {
        return Db::all('SELECT id, email, name, role, disabled, must_change, created_at, last_login_at
                        FROM admin_users ORDER BY created_at ASC');
    }

    public static function userByEmail(string $email): ?array
    {
        return Db::one('SELECT * FROM admin_users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]);
    }

    public static function userById(string $id): ?array
    {
        return Db::one('SELECT * FROM admin_users WHERE id = ? LIMIT 1', [$id]);
    }

    public static function createUser(string $email, string $name, string $role, string $passwordHash, bool $mustChange = true): string
    {
        $id = Util::uuid();
        Db::run(
            'INSERT INTO admin_users (id, email, name, role, password_hash, disabled, must_change, created_at)
             VALUES (?,?,?,?,?,0,?,?)',
            [$id, mb_strtolower(trim($email)), $name, $role, $passwordHash, $mustChange ? 1 : 0, Util::now()]
        );
        return $id;
    }

    /** @param array<string, mixed> $fields */
    public static function updateUser(string $id, array $fields): void
    {
        $allowed = ['email', 'name', 'role', 'password_hash', 'disabled', 'must_change', 'last_login_at'];
        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[]   = "{$key} = ?";
            $params[] = $key === 'email' ? mb_strtolower(trim((string) $value)) : $value;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        Db::run('UPDATE admin_users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public static function deleteUser(string $id): bool
    {
        return Db::run('DELETE FROM admin_users WHERE id = ?', [$id])->rowCount() > 0;
    }

    public static function countOwners(): int
    {
        return (int) Db::scalar("SELECT COUNT(*) FROM admin_users WHERE role = 'owner' AND disabled = 0");
    }

    // ------------------------------------------------------------------- email

    /**
     * Resolves an audience from the applications table.
     *
     * Deduplicated by address, because one person applying to two trials is
     * still one person and receiving the same message twice is how a trial
     * invitation starts to look like spam. The surviving row is the most recent
     * application, so the reference in the footer is the one they last quoted.
     *
     * @param array<string, string> $filters same shape as the applications list
     * @return array<int, array<string, mixed>>
     */
    public static function audience(array $filters): array
    {
        [$where, $params] = self::where($filters);

        $rows = Db::all(
            "SELECT id, application_id, trial, email, created_at
             FROM submissions
             {$where}
             ORDER BY created_at DESC",
            $params
        );

        $byEmail = [];
        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) $row['email']));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (isset($byEmail[$email])) {
                continue;
            }
            $byEmail[$email] = [
                'submission_id' => (string) $row['id'],
                'email'         => $email,
                'name'          => '',
                'reference'     => (string) $row['application_id'],
                'trial'         => (string) $row['trial'],
            ];
        }

        return array_values($byEmail);
    }

    /** @param array<int, string> $ids */
    public static function audienceByIds(array $ids): array
    {
        $ids = array_values(array_filter($ids, 'is_string'));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all(
            "SELECT id, application_id, trial, email FROM submissions
             WHERE id IN ({$placeholders}) ORDER BY created_at DESC",
            $ids
        );

        $byEmail = [];
        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) $row['email']));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($byEmail[$email])) {
                continue;
            }
            $byEmail[$email] = [
                'submission_id' => (string) $row['id'],
                'email'         => $email,
                'name'          => '',
                'reference'     => (string) $row['application_id'],
                'trial'         => (string) $row['trial'],
            ];
        }

        return array_values($byEmail);
    }

    /**
     * Most recent application per address, for a batch of addresses.
     *
     * One query per chunk rather than one per address: the compose page
     * resolves its audience on every render, and a pasted list of five hundred
     * would otherwise be five hundred round trips each time the page is drawn.
     *
     * @param array<int, string> $emails
     * @return array<string, array<string, mixed>> keyed by lowercased address
     */
    public static function submissionsByEmails(array $emails): array
    {
        $emails = array_values(array_unique(array_map(
            static fn($e): string => mb_strtolower(trim((string) $e)),
            $emails
        )));
        if ($emails === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Db::all(
                "SELECT id, application_id, trial, email FROM submissions
                 WHERE email IN ({$placeholders}) ORDER BY created_at ASC",
                $chunk
            );
            // Ascending, so the last write per address wins: the newest one.
            foreach ($rows as $row) {
                $found[mb_strtolower((string) $row['email'])] = $row;
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $message */
    public static function createMessage(array $message, string $actorEmail): string
    {
        $id = Util::uuid();
        Db::run(
            'INSERT INTO email_messages
                (id, subject, preheader, body, cta_label, cta_url, audience, audience_meta,
                 status, total, sent_count, failed_count, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,0,0,0,?,?)',
            [
                $id,
                mb_substr((string) $message['subject'], 0, 255),
                mb_substr((string) ($message['preheader'] ?? ''), 0, 255),
                (string) $message['body'],
                mb_substr((string) ($message['cta_label'] ?? ''), 0, 120),
                mb_substr((string) ($message['cta_url'] ?? ''), 0, 512),
                (string) ($message['audience'] ?? 'single'),
                json_encode($message['audience_meta'] ?? [], JSON_UNESCAPED_UNICODE),
                'queued',
                $actorEmail,
                Util::now(),
            ]
        );
        return $id;
    }

    /**
     * @param array<int, array<string, mixed>> $recipients
     * @return int the number actually inserted
     */
    public static function addRecipients(string $messageId, array $recipients): int
    {
        if ($recipients === []) {
            return 0;
        }

        $now = Util::now();
        $inserted = 0;

        Db::transaction(static function () use ($messageId, $recipients, $now, &$inserted): void {
            foreach ($recipients as $recipient) {
                Db::run(
                    'INSERT INTO email_recipients
                        (id, message_id, submission_id, email, name, reference, trial,
                         status, attempts, created_at)
                     VALUES (?,?,?,?,?,?,?,?,0,?)',
                    [
                        Util::uuid(),
                        $messageId,
                        $recipient['submission_id'] ?? null,
                        mb_substr((string) $recipient['email'], 0, 255),
                        mb_substr((string) ($recipient['name'] ?? ''), 0, 255),
                        mb_substr((string) ($recipient['reference'] ?? ''), 0, 32),
                        mb_substr((string) ($recipient['trial'] ?? ''), 0, 32),
                        'pending',
                        $now,
                    ]
                );
                $inserted++;
            }
        });

        Db::run('UPDATE email_messages SET total = ? WHERE id = ?', [$inserted, $messageId]);
        return $inserted;
    }

    public static function getMessage(string $id): ?array
    {
        $row = Db::one('SELECT * FROM email_messages WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            return null;
        }
        $row['audience_meta'] = json_decode((string) $row['audience_meta'], true) ?: [];
        return $row;
    }

    /** @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, perPage: int} */
    public static function listMessages(int $page = 1, int $perPage = 20): array
    {
        $total   = (int) Db::scalar('SELECT COUNT(*) FROM email_messages');
        $perPage = max(5, min(100, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Db::all(
            "SELECT id, subject, audience, status, total, sent_count, failed_count,
                    created_by, created_at, finished_at
             FROM email_messages ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}"
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /** @return array<int, array<string, mixed>> */
    public static function messageRecipients(string $messageId, string $status = 'all', int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        if ($status !== 'all' && in_array($status, ['pending', 'sending', 'sent', 'failed'], true)) {
            return Db::all(
                "SELECT * FROM email_recipients WHERE message_id = ? AND status = ?
                 ORDER BY created_at ASC, email ASC LIMIT {$limit}",
                [$messageId, $status]
            );
        }
        // Failures first — they are the rows someone opened this page to find.
        return Db::all(
            "SELECT * FROM email_recipients WHERE message_id = ?
             ORDER BY CASE status WHEN 'failed' THEN 0 WHEN 'pending' THEN 1
                                  WHEN 'sending' THEN 2 ELSE 3 END, email ASC
             LIMIT {$limit}",
            [$messageId]
        );
    }

    /**
     * Takes up to $limit pending rows for this worker.
     *
     * Two steps rather than one, because SQLite is usually built without
     * UPDATE ... LIMIT. The claim token makes it safe anyway: the UPDATE only
     * touches rows still pending, and the SELECT only returns rows carrying
     * this worker's token, so two workers racing cannot both take a row.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function claimRecipients(string $messageId, int $limit, string $claim): array
    {
        $limit = max(1, min(500, $limit));

        $ids = Db::all(
            "SELECT id FROM email_recipients
             WHERE message_id = ? AND status = 'pending'
             ORDER BY created_at ASC LIMIT {$limit}",
            [$messageId]
        );
        $ids = array_column($ids, 'id');
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Db::run(
            "UPDATE email_recipients
             SET status = 'sending', claim = ?, attempts = attempts + 1
             WHERE status = 'pending' AND id IN ({$placeholders})",
            [$claim, ...$ids]
        );

        return Db::all(
            "SELECT * FROM email_recipients WHERE claim = ? AND status = 'sending' ORDER BY created_at ASC",
            [$claim]
        );
    }

    public static function markRecipientSent(string $recipientId): void
    {
        Db::run(
            "UPDATE email_recipients SET status = 'sent', error = NULL, claim = NULL, sent_at = ? WHERE id = ?",
            [Util::now(), $recipientId]
        );
    }

    public static function markRecipientFailed(string $recipientId, string $error): void
    {
        Db::run(
            "UPDATE email_recipients SET status = 'failed', error = ?, claim = NULL WHERE id = ?",
            [mb_substr($error, 0, 255), $recipientId]
        );
    }

    /** Hands rows back when the transport died before they were attempted. */
    public static function releaseClaim(string $claim): int
    {
        return Db::run(
            "UPDATE email_recipients SET status = 'pending', claim = NULL WHERE claim = ? AND status = 'sending'",
            [$claim]
        )->rowCount();
    }

    /** Returns failed rows to the queue, for a retry after the cause is fixed. */
    public static function requeueFailed(string $messageId): int
    {
        $changed = Db::run(
            "UPDATE email_recipients SET status = 'pending', error = NULL, claim = NULL
             WHERE message_id = ? AND status = 'failed'",
            [$messageId]
        )->rowCount();

        if ($changed > 0) {
            Db::run("UPDATE email_messages SET status = 'queued', finished_at = NULL WHERE id = ?", [$messageId]);
            // Counters only: a requeued message is waiting, not mid-send, and
            // the full refresh would immediately relabel it 'sending'.
            self::refreshMessageCounts($messageId, false);
        }
        return $changed;
    }

    public static function cancelMessage(string $messageId): int
    {
        $changed = Db::run(
            "UPDATE email_recipients SET status = 'failed', error = 'Cancelled before sending', claim = NULL
             WHERE message_id = ? AND status IN ('pending', 'sending')",
            [$messageId]
        )->rowCount();

        Db::run(
            "UPDATE email_messages SET status = 'cancelled', finished_at = ? WHERE id = ?",
            [Util::now(), $messageId]
        );
        self::refreshMessageCounts($messageId, false);
        return $changed;
    }

    /** Recomputes the denormalised counters and closes the message when done. */
    public static function refreshMessageCounts(string $messageId, bool $updateStatus = true): array
    {
        $sent    = (int) Db::scalar("SELECT COUNT(*) FROM email_recipients WHERE message_id = ? AND status = 'sent'", [$messageId]);
        $failed  = (int) Db::scalar("SELECT COUNT(*) FROM email_recipients WHERE message_id = ? AND status = 'failed'", [$messageId]);
        $pending = (int) Db::scalar("SELECT COUNT(*) FROM email_recipients WHERE message_id = ? AND status IN ('pending', 'sending')", [$messageId]);

        Db::run('UPDATE email_messages SET sent_count = ?, failed_count = ? WHERE id = ?', [$sent, $failed, $messageId]);

        if ($updateStatus) {
            if ($pending === 0) {
                Db::run(
                    'UPDATE email_messages SET status = ?, finished_at = ? WHERE id = ?',
                    [$failed > 0 && $sent === 0 ? 'failed' : 'sent', Util::now(), $messageId]
                );
            } else {
                Db::run("UPDATE email_messages SET status = 'sending', finished_at = NULL WHERE id = ?", [$messageId]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'pending' => $pending];
    }

    public static function pendingRecipientCount(string $messageId): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM email_recipients WHERE message_id = ? AND status IN ('pending', 'sending')",
            [$messageId]
        );
    }

    /** Messages with work outstanding, oldest first — the worker's queue. */
    public static function messagesWithPending(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return Db::all(
            "SELECT m.* FROM email_messages m
             WHERE m.status IN ('queued', 'sending')
               AND EXISTS (SELECT 1 FROM email_recipients r
                           WHERE r.message_id = m.id AND r.status IN ('pending', 'sending'))
             ORDER BY m.created_at ASC LIMIT {$limit}"
        );
    }

    public static function deleteMessage(string $messageId): bool
    {
        Db::run('DELETE FROM email_recipients WHERE message_id = ?', [$messageId]);
        return Db::run('DELETE FROM email_messages WHERE id = ?', [$messageId])->rowCount() > 0;
    }

    // ------------------------------------------------------------ suppression

    /** @return array<int, string> the addresses that must not be written to */
    public static function suppressedAmong(array $emails): array
    {
        $emails = array_values(array_unique(array_map(
            static fn($e): string => mb_strtolower(trim((string) $e)),
            $emails
        )));
        if ($emails === []) {
            return [];
        }

        $found = [];
        // Chunked so a large audience does not build a statement with thousands
        // of placeholders, which MySQL will refuse.
        foreach (array_chunk($emails, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Db::all("SELECT email FROM email_suppressions WHERE email IN ({$placeholders})", $chunk);
            foreach ($rows as $row) {
                $found[] = (string) $row['email'];
            }
        }
        return $found;
    }

    public static function suppress(string $email, string $reason, string $note, string $actorEmail): void
    {
        $email = mb_strtolower(trim($email));
        Db::run('DELETE FROM email_suppressions WHERE email = ?', [$email]);
        Db::run(
            'INSERT INTO email_suppressions (email, reason, note, created_by, created_at) VALUES (?,?,?,?,?)',
            [$email, $reason, mb_substr($note, 0, 255), $actorEmail, Util::now()]
        );
    }

    public static function unsuppress(string $email): bool
    {
        return Db::run('DELETE FROM email_suppressions WHERE email = ?', [mb_strtolower(trim($email))])->rowCount() > 0;
    }

    public static function listSuppressions(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return Db::all("SELECT * FROM email_suppressions ORDER BY created_at DESC LIMIT {$limit}");
    }

    // ------------------------------------------------------------------- audit

    public static function audit(string $actorEmail, string $action, string $targetType, ?string $targetId = null, array $meta = []): void
    {
        try {
            Db::run(
                'INSERT INTO audit_events (actor_email, action, target_type, target_id, meta, ip_hash, created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$actorEmail, $action, $targetType, $targetId, json_encode($meta, JSON_UNESCAPED_UNICODE), Util::ipHash(), Util::now()]
            );
        } catch (\Throwable $e) {
            // Auditing must never take the request down with it.
            error_log('Audit write failed: ' . $e->getMessage());
        }
    }

    public static function listAudit(int $page = 1, int $perPage = 50): array
    {
        $total   = (int) Db::scalar('SELECT COUNT(*) FROM audit_events');
        $perPage = max(10, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Db::all("SELECT * FROM audit_events ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
        foreach ($rows as &$row) {
            $row['meta'] = json_decode((string) $row['meta'], true) ?: [];
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /** Audit trail for one record, shown on the detail page. */
    public static function auditFor(string $targetType, string $targetId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = Db::all(
            "SELECT * FROM audit_events WHERE target_type = ? AND target_id = ?
             ORDER BY created_at DESC LIMIT {$limit}",
            [$targetType, $targetId]
        );
        foreach ($rows as &$row) {
            $row['meta'] = json_decode((string) $row['meta'], true) ?: [];
        }
        unset($row);
        return $rows;
    }

    /** Housekeeping — call from a cron job if the audit table grows large. */
    public static function pruneAudit(int $keepDays = 365): int
    {
        return Db::run('DELETE FROM audit_events WHERE created_at < ?', [Util::daysAgo($keepDays)])->rowCount();
    }
}
