<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Synchroniser-token CSRF protection for the admin dashboard.
 *
 * Every state-changing form carries a token bound to the session; POSTs without
 * a matching one are rejected. SameSite=Lax on the session cookie already stops
 * most cross-site form posts — this closes the rest, including same-site
 * subdomain attacks on shared hosting.
 */
final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::KEY];
    }

    /** Hidden input to drop into every admin form. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::KEY . '" value="' . Util::e(self::token()) . '">';
    }

    public static function valid(?string $token): bool
    {
        Auth::start();
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($token)
            && $token !== ''
            && is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $token);
    }

    /**
     * Gate for POST handlers: enforces the method and the token, then returns.
     * Anything that fails here never reaches the database.
     */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('Method not allowed');
        }
        $token = $_POST[self::KEY] ?? null;
        if (!self::valid(is_string($token) ? $token : null)) {
            Repo::audit(Auth::email(), 'csrf.rejected', 'session', null, [
                'script' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
            ]);
            http_response_code(419);
            exit('Your session expired. Go back, reload the page and try again.');
        }
    }
}
