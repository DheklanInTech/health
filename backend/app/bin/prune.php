<?php
/**
 * Housekeeping, intended for a weekly cron job.
 *
 *   php app/bin/prune.php              # audit events older than 365 days
 *   php app/bin/prune.php --days=730
 *
 * Only ever touches the audit and login-attempt tables. Applications are never
 * deleted automatically — retention of participant data is a decision for the
 * study team, not a cron job.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Olisa\Db;
use Olisa\Repo;
use Olisa\Util;

$days = 365;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(30, (int) $m[1]);
    }
}

$audit = Repo::pruneAudit($days);
echo "Removed {$audit} audit events older than {$days} days.\n";

$logins = Db::run('DELETE FROM login_attempts WHERE created_at < ?', [Util::daysAgo(7)])->rowCount();
echo "Removed {$logins} login attempts older than 7 days.\n";

// Old session files are not cleaned by PHP's GC reliably on shared hosting.
$sessions = 0;
$dir = APP_ROOT . '/storage/sessions';
if (is_dir($dir)) {
    foreach (glob($dir . '/sess_*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 86400) {
            @unlink($file);
            $sessions++;
        }
    }
}
echo "Removed {$sessions} expired session files.\n";
