<?php
/**
 * The do-not-email list.
 *
 * Applied when an audience is resolved rather than when a message is sent, so
 * an opt-out cannot be undone by someone re-running an old filter, and the
 * count shown on the compose page is the truth about who will be written to.
 */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Csrf;
use Olisa\Repo;
use Olisa\Util;
use Olisa\View;

$user = Auth::requireLogin();
Auth::requireCan('edit');

const REASONS = [
    'unsubscribed' => 'Asked to stop',
    'bounced'      => 'Address does not exist',
    'complained'   => 'Marked us as spam',
    'withdrawn'    => 'Withdrew from the trial',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $added = 0;
        $reason = array_key_exists((string) ($_POST['reason'] ?? ''), REASONS)
            ? (string) $_POST['reason'] : 'unsubscribed';
        $note = trim((string) ($_POST['note'] ?? ''));

        foreach (preg_split('/[\s,;]+/', (string) ($_POST['emails'] ?? '')) ?: [] as $email) {
            $email = mb_strtolower(trim($email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            Repo::suppress($email, $reason, $note, $user['email']);
            $added++;
        }

        if ($added > 0) {
            Repo::audit($user['email'], 'email.suppressed', 'email', null, ['count' => $added, 'reason' => $reason]);
            View::setFlash('ok', $added . ' address' . ($added === 1 ? '' : 'es') . ' added.');
        } else {
            View::setFlash('error', 'No valid email addresses in that.');
        }
    } elseif ($action === 'remove') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        if (Repo::unsuppress($email)) {
            Repo::audit($user['email'], 'email.unsuppressed', 'email', null, ['email' => $email]);
            View::setFlash('ok', $email . ' can be emailed again.');
        }
    }

    Util::redirect('suppressions.php');
}

$rows = Repo::listSuppressions(500);

View::header('Do-not-email list', [
    'subtitle' => count($rows) . ' address' . (count($rows) === 1 ? '' : 'es') . ' excluded from every send',
    'actions'  => '<a class="btn secondary" href="messages.php">Back to messages</a>',
]);
View::flash();
?>

<div class="grid grid-main">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Address</th><th>Reason</th><th>Added by</th><th>When</th><th></th></tr></thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="5"><div class="empty">
          <strong>Nobody is excluded</strong>
          Add an address here the moment someone asks to stop hearing from the trial.
        </div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= Util::e((string) $row['email']) ?>
            <?php if ($row['note']): ?>
              <div class="xsmall muted"><?= Util::e((string) $row['note']) ?></div>
            <?php endif; ?>
          </td>
          <td class="tight"><span class="badge"><?= Util::e(REASONS[(string) $row['reason']] ?? (string) $row['reason']) ?></span></td>
          <td class="tight muted small"><?= Util::e((string) ($row['created_by'] ?: '—')) ?></td>
          <td class="tight muted small"><?= Util::e(Util::relativeTime((string) $row['created_at'])) ?></td>
          <td class="tight">
            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="email" value="<?= Util::e((string) $row['email']) ?>">
              <label class="xsmall row nowrap" style="gap:5px">
                <input type="checkbox" name="confirm" value="yes" required>
                <button type="submit" class="btn secondary small">Remove</button>
              </label>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <section class="card">
    <div class="card-head"><h2>Add addresses</h2></div>
    <form method="post" class="stack">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="add">

      <div class="field">
        <label for="emails">Addresses</label>
        <textarea id="emails" name="emails" rows="4" required
                  placeholder="One per line, or separated by commas"></textarea>
      </div>

      <div class="field">
        <label for="reason">Reason</label>
        <select id="reason" name="reason" style="width:100%">
          <?php foreach (REASONS as $value => $label): ?>
            <option value="<?= Util::e($value) ?>"><?= Util::e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="note">Note (optional)</label>
        <input type="text" id="note" name="note" maxlength="255" style="width:100%">
      </div>

      <button type="submit" class="btn">Add to the list</button>
    </form>

    <p class="xsmall muted" style="margin-bottom:0">
      Suppression only stops campaign email. It does not stop a coordinator
      phoning, and it does not withdraw anyone from a trial — change their
      application status for that.
    </p>
  </section>
</div>

<?php View::footer(); ?>
