<?php
/**
 * Application bootstrap. Lives OUTSIDE the web root — nothing here is
 * directly reachable over HTTP.
 *
 * Every entry point in public_html reaches this through public_html/_init.php.
 */

declare(strict_types=1);

if (defined('OLISA_BOOTSTRAPPED')) {
    return;
}
define('OLISA_BOOTSTRAPPED', true);

define('APP_ROOT', __DIR__);
define('APP_SRC', APP_ROOT . '/src');

// Shared hosting often has display_errors on by default. Never leak stack
// traces — or PHI — to a visitor; log instead.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$logDir = APP_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php-error.log');

date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Olisa\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('Olisa\\')));
    $file = APP_SRC . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

\Olisa\Config::load();

// In development, surface errors in the log loudly; in production keep quiet.
if (\Olisa\Config::get('app.debug')) {
    ini_set('display_errors', '1');
}
