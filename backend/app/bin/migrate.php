<?php
/**
 * Creates or updates the database tables. Safe to run repeatedly.
 *
 *   php app/bin/migrate.php
 *
 * On cPanel use the Terminal, or Cron Jobs -> run once, or paste
 * app/schema/mysql.sql into phpMyAdmin instead.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Olisa\Config;
use Olisa\Db;

$driver = Config::str('db.driver');
echo "Applying schema using the {$driver} driver...\n";

try {
    Db::migrate();
} catch (Throwable $e) {
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}

$tables = ['submissions', 'submission_notes', 'admin_users', 'audit_events', 'login_attempts',
          'email_messages', 'email_recipients', 'email_suppressions'];
foreach ($tables as $table) {
    $count = Db::scalar("SELECT COUNT(*) FROM {$table}");
    printf("  %-18s ok (%d rows)\n", $table, (int) $count);
}

$admins = (int) Db::scalar('SELECT COUNT(*) FROM admin_users');
echo "\nDone.\n";

if ($admins === 0) {
    echo "\nNo admin accounts exist yet. Create the first one with:\n";
    echo "  php app/bin/create-admin.php\n";
}
