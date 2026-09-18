<?php
/**
 * Bootstrap shim. This is the only file inside the web root that knows where
 * the private application directory is.
 *
 * Deployed layout on cPanel:
 *   /home/<user>/olisa-app/      <- private: src, config.php, schema, storage
 *   /home/<user>/public_html/    <- this file, api/, admin/, the static site
 *
 * Direct HTTP access is denied by .htaccess, and the guard below makes that
 * belt-and-braces: opened on its own it defines nothing and prints nothing.
 */

declare(strict_types=1);

if (!defined('OLISA_ENTRY')) {
    http_response_code(404);
    exit;
}

$candidates = [];

// 1. Explicit override — set with `SetEnv OLISA_APP_PATH ...` in .htaccess.
$fromEnv = getenv('OLISA_APP_PATH');
if (is_string($fromEnv) && $fromEnv !== '') {
    $candidates[] = rtrim($fromEnv, '/');
}

// 2. Standard cPanel layout: sibling of public_html.
$candidates[] = dirname(__DIR__) . '/olisa-app';

// 3. Repository layout, for local development.
$candidates[] = dirname(__DIR__) . '/app';
$candidates[] = dirname(__DIR__, 2) . '/backend/app';

foreach ($candidates as $path) {
    if (is_file($path . '/bootstrap.php')) {
        require_once $path . '/bootstrap.php';
        return;
    }
}

http_response_code(500);
error_log('olisa: bootstrap.php not found. Looked in: ' . implode(', ', $candidates));
exit('Application is not configured. See DEPLOYMENT.md.');
