<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Authentication on native PHP sessions.
 *
 * Session files are written into the app's own storage directory rather than
 * the system temp dir — on shared hosting /tmp can be readable by neighbouring
 * accounts, and these sessions guard health information.
 *
 * Passwords use password_hash() with the platform default (argon2id where
 * available, bcrypt otherwise) and are transparently re-hashed on login when
 * the default changes.
 */
final class Auth
{
    private const IDLE_TIMEOUT = 1800;   // 30 minutes of inactivity
    private const REGEN_EVERY  = 300;    // rotate the session ID every 5 minutes

    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $path = APP_ROOT . '/storage/sessions';
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        if (is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }

        session_name(Config::str('session.name', 'TRIALPATH_ADMIN'));
        session_set_cookie_params([
            'lifetime' => 0,               // session cookie — dies with the browser
            'path'     => '/',
            'secure'   => Config::bool('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) Config::int('session.lifetime', 43200));

        session_start();
        self::$started = true;

        self::enforceTimeouts();
    }

    private static function enforceTimeouts(): void
    {
        if (!isset($_SESSION['uid'])) {
            return;
        }

        $now  = time();
        $seen = (int) ($_SESSION['last_seen'] ?? 0);

        if ($seen > 0 && ($now - $seen) > self::IDLE_TIMEOUT) {
            self::logout();
            return;
        }

        $absolute = (int) ($_SESSION['started_at'] ?? $now);
        if (($now - $absolute) > Config::int('session.lifetime', 43200)) {
            self::logout();
            return;
        }

        // Rotate the ID periodically to shrink the window for a fixated session.
        $regen = (int) ($_SESSION['regenerated_at'] ?? 0);
        if ($now - $regen > self::REGEN_EVERY) {
            session_regenerate_id(true);
            $_SESSION['regenerated_at'] = $now;
        }

        $_SESSION['last_seen'] = $now;
    }

    /**
     * @return array{ok: bool, error?: string, user?: array<string, mixed>}
     */
    public static function login(string $email, string $password): array
    {
        self::start();

        $email  = mb_strtolower(trim($email));
        $ipHash = Util::ipHash();

        if (Throttle::isLockedOut($email, $ipHash)) {
            Repo::audit($email, 'login.throttled', 'session');
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in 15 minutes.'];
        }

        $user = Repo::userByEmail($email);

        // Always spend the cost of a hash, even for an unknown address, so the
        // response time does not reveal which accounts exist.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $passwordOk = password_verify($password, (string) $hash);

        if ($user === null || !$passwordOk) {
            Throttle::recordLogin($email, $ipHash, false);
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        if ((int) $user['disabled'] === 1) {
            Throttle::recordLogin($email, $ipHash, false);
            Repo::audit($email, 'login.disabled', 'session', (string) $user['id']);
            return ['ok' => false, 'error' => 'This account has been disabled.'];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Repo::updateUser((string) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        // New session ID on privilege change — defeats session fixation.
        session_regenerate_id(true);
        $_SESSION['uid']            = $user['id'];
        $_SESSION['email']          = $user['email'];
        $_SESSION['name']           = $user['name'];
        $_SESSION['role']           = $user['role'];
        $_SESSION['must_change']    = (int) $user['must_change'] === 1;
        $_SESSION['started_at']     = time();
        $_SESSION['last_seen']      = time();
        $_SESSION['regenerated_at'] = time();

        Repo::updateUser((string) $user['id'], ['last_login_at' => Util::now()]);
        Throttle::recordLogin($email, $ipHash, true);
        Repo::audit((string) $user['email'], 'login.success', 'session', (string) $user['id']);

        return ['ok' => true, 'user' => $user];
    }

    public static function logout(): void
    {
        self::start();
        if (isset($_SESSION['email'])) {
            Repo::audit((string) $_SESSION['email'], 'logout', 'session', (string) ($_SESSION['uid'] ?? ''));
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['uid']);
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        self::start();
        if (!isset($_SESSION['uid'])) {
            return null;
        }
        return [
            'id'          => $_SESSION['uid'],
            'email'       => $_SESSION['email'] ?? '',
            'name'        => $_SESSION['name'] ?? '',
            'role'        => $_SESSION['role'] ?? 'viewer',
            'must_change' => (bool) ($_SESSION['must_change'] ?? false),
        ];
    }

    public static function email(): string
    {
        return (string) (self::user()['email'] ?? 'unknown');
    }

    /** Redirects to the login page, preserving where the visitor was heading. */
    public static function requireLogin(): array
    {
        self::start();
        $user = self::user();
        if ($user === null) {
            $target = $_SERVER['REQUEST_URI'] ?? '';
            Util::redirect('login.php' . ($target ? '?next=' . urlencode((string) $target) : ''));
        }
        // A user mid-forced-password-change may only reach the change page.
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($user['must_change'] && !in_array($script, ['password.php', 'logout.php'], true)) {
            Util::redirect('password.php?forced=1');
        }
        return $user;
    }

    /**
     * Roles are a simple ladder: owner > admin > viewer.
     * Viewers can read everything but change nothing.
     */
    public static function can(string $capability): bool
    {
        $role = (string) (self::user()['role'] ?? '');
        return match ($capability) {
            'view'            => in_array($role, ['owner', 'admin', 'viewer'], true),
            'edit'            => in_array($role, ['owner', 'admin'], true),
            'export'          => in_array($role, ['owner', 'admin'], true),
            'delete', 'users' => $role === 'owner',
            default           => false,
        };
    }

    public static function requireCan(string $capability): void
    {
        if (!self::can($capability)) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><title>Not allowed</title>'
               . '<p style="font:16px system-ui;padding:40px">You do not have permission to do that. '
               . '<a href="index.php">Back to the dashboard</a>.</p>';
            exit;
        }
    }
}
