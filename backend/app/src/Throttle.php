<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Abuse limits, backed by the database so they survive across PHP processes —
 * an in-memory counter is useless on shared hosting where every request may be
 * a fresh process.
 *
 * Two things are throttled: admin login attempts (credential stuffing) and
 * public form submissions (spam floods).
 */
final class Throttle
{
    public static function recordLogin(string $identifier, string $ipHash, bool $successful): void
    {
        try {
            Db::run(
                'INSERT INTO login_attempts (identifier, ip_hash, successful, created_at) VALUES (?,?,?,?)',
                [mb_strtolower($identifier), $ipHash, $successful ? 1 : 0, Util::now()]
            );
            if ($successful) {
                // A good password clears the slate for that account+IP pair.
                Db::run('DELETE FROM login_attempts WHERE identifier = ? AND ip_hash = ? AND successful = 0',
                    [mb_strtolower($identifier), $ipHash]);
            }
            self::prune();
        } catch (\Throwable $e) {
            error_log('Throttle write failed: ' . $e->getMessage());
        }
    }

    public static function failedCount(string $identifier, string $ipHash): int
    {
        $window = Config::int('limits.login_window', 900);
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier = ? AND ip_hash = ? AND successful = 0 AND created_at >= ?',
            [mb_strtolower($identifier), $ipHash, gmdate('Y-m-d H:i:s', time() - $window)]
        );
    }

    public static function isLockedOut(string $identifier, string $ipHash): bool
    {
        return self::failedCount($identifier, $ipHash) >= Config::int('limits.login_attempts', 8);
    }

    /**
     * Public submission limits.
     *
     * @return string|null a reason when the request should be rejected
     */
    public static function submissionBlocked(string $ipHash): ?string
    {
        $perHour = Config::int('limits.submissions_per_hour', 5);
        if ($perHour > 0 && Repo::countRecentByIp($ipHash, 3600) >= $perHour) {
            return 'Too many applications from this connection. Please try again later.';
        }

        $perDay = Config::int('limits.submissions_per_day', 15);
        if ($perDay > 0 && Repo::countRecentByIp($ipHash, 86400) >= $perDay) {
            return 'Daily application limit reached for this connection.';
        }

        return null;
    }

    /** Keeps the attempts table small; runs opportunistically, ~1 call in 50. */
    private static function prune(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        Db::run('DELETE FROM login_attempts WHERE created_at < ?', [Util::daysAgo(7)]);
    }
}
