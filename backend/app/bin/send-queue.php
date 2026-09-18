<?php
/**
 * Drains the email queue. Run it from cron so a large campaign finishes without
 * anyone having to sit on the dashboard pressing "Send the rest":
 *
 *   * * * * * /usr/local/bin/php /home/<user>/olisa-app/bin/send-queue.php >/dev/null 2>&1
 *
 * Safe to overlap — recipients are claimed with a token, so two runs of this
 * script cannot send the same message to the same person twice. The lock below
 * simply stops a backlog of processes building up when a send is slow.
 *
 *   --limit=N    stop after N messages (default: all with work outstanding)
 *   --seconds=N  stop after roughly N seconds (default 50, to fit a 1-minute cron)
 *   --quiet      print nothing unless something failed
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Olisa\Config;
use Olisa\Outbox;
use Olisa\Repo;

$options = getopt('', ['limit::', 'seconds::', 'quiet']);
$limit   = isset($options['limit']) ? max(1, (int) $options['limit']) : 10;
$seconds = isset($options['seconds']) ? max(5, (int) $options['seconds']) : 50;
$quiet   = array_key_exists('quiet', $options);

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

// One drain at a time. A stale lock from a killed process is ignored after ten
// minutes rather than wedging the queue forever.
$lockFile = APP_ROOT . '/storage/send-queue.lock';
$lock = @fopen($lockFile, 'c+');
if ($lock === false) {
    fwrite(STDERR, "Cannot open the lock file at {$lockFile}\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $age = time() - (int) @filemtime($lockFile);
    if ($age < 600) {
        $say('Another send is already running. Nothing to do.');
        exit(0);
    }
    $say('Ignoring a stale lock (' . $age . 's old).');
}
@touch($lockFile);

$deadline = microtime(true) + $seconds;
$messages = Repo::messagesWithPending($limit);

if ($messages === []) {
    $say('Queue is empty.');
    flock($lock, LOCK_UN);
    exit(0);
}

$totalSent = 0;
$totalFailed = 0;
$exitCode = 0;

foreach ($messages as $message) {
    if (microtime(true) >= $deadline) {
        $say('Out of time — the rest stays queued for the next run.');
        break;
    }

    $id = (string) $message['id'];
    $result = Outbox::drain($id, Config::int('mail.batch_size', 25), $deadline);

    $totalSent   += $result['sent'];
    $totalFailed += $result['failed'];

    $say(sprintf(
        '  %s  sent %d, failed %d, remaining %d%s',
        substr($id, 0, 8),
        $result['sent'],
        $result['failed'],
        $result['remaining'],
        $result['aborted'] !== null ? '  [' . $result['aborted'] . ']' : ''
    ));

    // A transport that refuses to open will refuse for every message; stop
    // rather than marking the whole queue as attempted.
    if ($result['aborted'] !== null && $result['sent'] === 0) {
        fwrite(STDERR, 'Sending stopped: ' . $result['aborted'] . "\n");
        $exitCode = 1;
        break;
    }
}

$say(sprintf("Done. %d sent, %d failed.", $totalSent, $totalFailed));

flock($lock, LOCK_UN);
fclose($lock);
exit($exitCode);
