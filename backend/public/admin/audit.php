<?php
/**
 * Activity log — who did what, and when.
 *
 * Append-only from the application's point of view: there is no edit or delete
 * path in the dashboard, only the pruning cron described in DEPLOYMENT.md.
 */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Repo;
use Olisa\Util;
use Olisa\View;

Auth::requireLogin();

$page   = max(1, (int) ($_GET['page'] ?? 1));
$result = Repo::listAudit($page, 50);

/** Plain-English description of an event. */
function describe(array $event): string
{
    $action = (string) $event['action'];
    $meta   = (array) $event['meta'];

    return match ($action) {
        'login.success'            => 'Signed in',
        'login.throttled'          => 'Sign-in blocked — too many failed attempts',
        'login.disabled'           => 'Sign-in refused — account disabled',
        'logout'                   => 'Signed out',
        'csrf.rejected'            => 'A form was rejected as expired or forged',
        'submission.created'       => 'New application received'
            . (isset($meta['trial']) ? ' — ' . \Olisa\Trials::name((string) $meta['trial']) : ''),
        'submission.status'        => 'Changed status from ' . ($meta['from'] ?? '?') . ' to ' . ($meta['to'] ?? '?'),
        'submission.bulk_status'   => 'Set ' . ($meta['count'] ?? 0) . ' applications to ' . ($meta['to'] ?? $meta['status'] ?? '?'),
        'submission.note_added'    => 'Added a note',
        'submission.deleted'       => 'Deleted application ' . ($meta['application_id'] ?? ''),
        'submissions.exported'     => 'Exported ' . ($meta['rows'] ?? '?') . ' applications to CSV',
        'user.created'             => 'Created the account ' . ($meta['email'] ?? ''),
        'user.updated'             => 'Updated the account ' . ($meta['email'] ?? ''),
        'user.deleted'             => 'Removed the account ' . ($meta['email'] ?? ''),
        'user.password_changed'    => 'Changed a password',
        default                    => ucfirst(str_replace(['.', '_'], ' ', $action)),
    };
}

function tone(string $action): string
{
    return match (true) {
        str_contains($action, 'deleted'), str_contains($action, 'throttled'),
        str_contains($action, 'rejected'), str_contains($action, 'disabled') => 'serious',
        str_contains($action, 'exported')                                    => 'warn',
        str_contains($action, 'created')                                     => 'good',
        default                                                              => '',
    };
}

View::header('Activity log', [
    'subtitle' => number_format($result['total']) . ' recorded event' . ($result['total'] === 1 ? '' : 's'),
]);
View::flash();
?>

<div class="alert info">
  Every sign-in, status change, export and deletion is recorded here. Screening
  answers and note contents are deliberately <strong>not</strong> copied into
  this log — only the fact that something happened.
</div>

<?php if ($result['rows'] === []): ?>
  <div class="table-wrap"><div class="empty"><strong>Nothing recorded yet</strong>Activity appears here as the dashboard is used.</div></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>When</th><th>Who</th><th>What happened</th><th>Record</th></tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $event): ?>
        <tr>
          <td class="tight muted small nowrap" title="<?= Util::e(Util::formatDate((string) $event['created_at'])) ?>">
            <?= Util::e(Util::relativeTime((string) $event['created_at'])) ?>
          </td>
          <td class="tight">
            <?php if ($event['actor_email'] === 'public'): ?>
              <span class="badge">Public form</span>
            <?php else: ?>
              <?= Util::e((string) $event['actor_email']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php $tone = tone((string) $event['action']); ?>
            <?php if ($tone !== ''): ?>
              <span class="badge <?= $tone ?>" style="margin-right:8px"><?= Util::e(explode('.', (string) $event['action'])[1] ?? '') ?></span>
            <?php endif; ?>
            <?= Util::e(describe($event)) ?>
          </td>
          <td class="tight">
            <?php if ($event['target_type'] === 'submission' && !empty($event['target_id'])): ?>
              <a class="mono small" href="submission.php?id=<?= Util::e((string) $event['target_id']) ?>">Open</a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="pager">
    <div class="info">Page <?= $result['page'] ?> of <?= $result['pages'] ?></div>
    <div class="links">
      <?php if ($result['page'] > 1): ?>
        <a href="audit.php?page=<?= $result['page'] - 1 ?>" rel="prev">Previous</a>
      <?php else: ?><span class="disabled">Previous</span><?php endif; ?>

      <?php if ($result['page'] < $result['pages']): ?>
        <a href="audit.php?page=<?= $result['page'] + 1 ?>" rel="next">Next</a>
      <?php else: ?><span class="disabled">Next</span><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php View::footer(); ?>
