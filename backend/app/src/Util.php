<?php

declare(strict_types=1);

namespace Olisa;

final class Util
{
    /** RFC 4122 v4 UUID from a CSPRNG. */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Uniform UTC timestamp. Every row in the database is written with this. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    public static function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    /**
     * Participant-facing reference, e.g. TP-WL-7K4D92.
     * Excludes I/O/0/1 so it can be read aloud over the phone without confusion.
     */
    public static function applicationId(string $prefix): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'TP-' . $prefix . '-' . $code;
    }

    /** Best-guess client IP, trusting proxy headers only when configured to. */
    public static function clientIp(): string
    {
        $trustProxy = Config::bool('security.trust_proxy');
        if ($trustProxy) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
                if (!empty($_SERVER[$header])) {
                    $first = trim(explode(',', (string) $_SERVER[$header])[0]);
                    if (filter_var($first, FILTER_VALIDATE_IP)) {
                        return $first;
                    }
                }
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /**
     * Keyed hash of the client IP. Enough to rate-limit and spot abuse, but not
     * reversible into an address — the raw IP of someone disclosing health
     * information is never written to disk.
     */
    public static function ipHash(?string $ip = null): string
    {
        $ip ??= self::clientIp();
        $key = Config::str('security.hash_key');
        if ($key === '') {
            // Fail loudly in development, degrade safely in production.
            error_log('security.hash_key is not set — IP hashes are unsalted. Set HASH_KEY in config.php.');
            $key = 'unsalted-fallback';
        }
        return hash_hmac('sha256', $ip, $key);
    }

    public static function userAgent(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return mb_substr(is_string($ua) ? $ua : '', 0, 250);
    }

    /** HTML-escape for output. Always used when echoing anything from the database. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 302);
        exit;
    }

    /** Builds a query string, dropping empty values so URLs stay readable. */
    public static function qs(array $params): string
    {
        $clean = array_filter(
            $params,
            static fn($v) => $v !== null && $v !== '' && $v !== 'all'
        );
        return $clean === [] ? '' : '?' . http_build_query($clean);
    }

    public static function relativeTime(string $timestamp): string
    {
        $then = strtotime($timestamp . ' UTC');
        if ($then === false) {
            return $timestamp;
        }
        $diff = time() - $then;
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return floor($diff / 60) . 'm ago';
        if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return gmdate('j M Y', $then);
    }

    public static function formatDate(string $timestamp, string $format = 'j M Y, H:i'): string
    {
        $then = strtotime($timestamp . ' UTC');
        return $then === false ? $timestamp : gmdate($format, $then) . ' UTC';
    }
}
