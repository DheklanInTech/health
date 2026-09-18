<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Configuration, merged from three places in increasing priority:
 *   1. the defaults below
 *   2. config.php sitting next to bootstrap.php (copy of config.example.php)
 *   3. environment variables (handy for CI and for the cPanel Node/PHP env UI)
 *
 * config.php holds database credentials and the app key, so it lives outside
 * the web root and should be chmod 600.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        self::$values = [
            'app.name'        => 'Trial Path',
            'app.debug'       => false,
            // Public origin, used for absolute links in emails.
            'app.url'         => '',
            // Where the admin dashboard is mounted, relative to the docroot.
            'app.admin_base'  => '/admin',

            'db.driver'       => 'mysql',   // mysql | sqlite
            'db.host'         => 'localhost',
            'db.port'         => 3306,
            'db.name'         => '',
            'db.user'         => '',
            'db.password'     => '',
            'db.charset'      => 'utf8mb4',
            // Only used when db.driver = sqlite (local development).
            'db.sqlite_path'  => APP_ROOT . '/storage/olisa.sqlite',

            'session.name'     => 'TRIALPATH_ADMIN',
            'session.lifetime' => 43200,    // 12 hours
            'session.secure'   => true,     // set false only for plain-http local testing

            // Public submission endpoint limits, per IP.
            'limits.submissions_per_hour' => 5,
            'limits.submissions_per_day'  => 15,
            // Failed logins before the account+IP pair is locked out.
            'limits.login_attempts'       => 8,
            'limits.login_window'         => 900,   // 15 minutes
            'limits.login_lockout'        => 900,

            // Email notification on each new submission. Shared hosting mail()
            // is unreliable for deliverability — see DEPLOYMENT.md for SMTP.
            'mail.notify'     => false,
            'mail.to'         => '',
            'mail.from'       => '',
            'mail.from_name'  => '',
            'mail.reply_to'   => '',

            // Leave mail.smtp_host empty to use PHP's mail(). Set it to send
            // through an authenticated mailbox instead, which is what bulk
            // sending from the dashboard needs — see DEPLOYMENT.md.
            'mail.smtp_host'        => '',
            'mail.smtp_port'        => 587,
            'mail.smtp_user'        => '',
            'mail.smtp_password'    => '',
            'mail.smtp_encryption'  => 'tls',   // tls (587) | ssl (465) | '' (none)
            'mail.smtp_timeout'     => 20,
            'mail.smtp_verify_peer' => true,
            'mail.smtp_helo'        => '',

            // Messages per SMTP batch, and how long a web request may spend
            // sending before handing the rest back to the queue.
            'mail.batch_size'       => 25,
            'mail.request_budget'   => 15,      // seconds

            // Origins allowed to POST to the public endpoint. Empty = same-origin only.
            'security.allowed_origins' => [],
            // Pepper mixed into the IP hash so stored hashes are not reversible
            // via a rainbow table of the IPv4 space. MUST be set in config.php.
            'security.hash_key' => '',
        ];

        $path ??= APP_ROOT . '/config.php';
        if (is_file($path)) {
            /** @var array<string, mixed> $fromFile */
            $fromFile = require $path;
            if (is_array($fromFile)) {
                self::$values = array_merge(self::$values, $fromFile);
            }
        }

        foreach (self::envMap() as $env => $key) {
            $value = getenv($env);
            if ($value !== false && $value !== '') {
                self::$values[$key] = self::coerce($value);
            }
        }

        self::$loaded = true;
    }

    /** @return array<string, string> */
    private static function envMap(): array
    {
        return [
            'APP_DEBUG'     => 'app.debug',
            'APP_URL'       => 'app.url',
            'DB_DRIVER'     => 'db.driver',
            'DB_HOST'       => 'db.host',
            'DB_PORT'       => 'db.port',
            'DB_NAME'       => 'db.name',
            'DB_USER'       => 'db.user',
            'DB_PASSWORD'   => 'db.password',
            'DB_SQLITE_PATH' => 'db.sqlite_path',
            'SESSION_SECURE' => 'session.secure',
            'MAIL_NOTIFY'   => 'mail.notify',
            'MAIL_TO'       => 'mail.to',
            'MAIL_FROM'     => 'mail.from',
            'MAIL_FROM_NAME' => 'mail.from_name',
            'MAIL_REPLY_TO' => 'mail.reply_to',
            'SMTP_HOST'     => 'mail.smtp_host',
            'SMTP_PORT'     => 'mail.smtp_port',
            'SMTP_USER'     => 'mail.smtp_user',
            'SMTP_PASSWORD' => 'mail.smtp_password',
            'SMTP_ENCRYPTION' => 'mail.smtp_encryption',
            'HASH_KEY'      => 'security.hash_key',
        ];
    }

    private static function coerce(string $value): string|bool|int
    {
        $lower = strtolower($value);
        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;
        if (ctype_digit($value)) return (int) $value;
        return $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key, false);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function str(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }
}
