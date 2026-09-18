<?php
/**
 * One sent message: what it said, who it went to, and what happened to each
 * address. Also where a partly-sent batch is finished off by hand.
 */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Config;
use Olisa\Csrf;
use Olisa\Outbox;
use Olisa\Repo;
use Olisa\Trials;
use Olisa\Util;
use Olisa\View;

$user = Auth::requireLogin();
Auth::requireCan('edit');

$id = trim((string) ($_GET['id'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requirePost();
    $id     = trim((string) ($_POST['id'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');
    $message = Repo::getMessage($id);

    if ($message === null) {
        View::setFlash('error', 'That message no longer exists.');
        Util::redirect('messages.php');
    }

    if ($action === 'drain') {
        $budget = max(5, Config::int('mail.request_budget', 15));
        $result = Outbox::drain($id, Config::int('mail.batch_size', 25), microtime(true) + $budget);

        Repo::audit($user['email'], 'email.sent_batch', 'email', $id, [
            'sent' => $result['sent'], 'failed' => $result['failed'], 'remaining' => $result['remaining'],
        ]);

        if ($result['aborted'] !== null && $result['sent'] === 0) {
            View::setFlash('error', 'Sending stopped: ' . $result['aborted']);
        } elseif ($result['remaining'] > 0) {
            View::setFlash('ok', $result['sent'] . ' more sent. ' . $result['remaining'] . ' still queued.');
        } else {
            View::setFlash('ok', 'Finished — ' . $result['sent'] . ' sent in this run.');
        }
    } elseif ($action === 'retry') {
        $changed = Repo::requeueFailed($id);
        Repo::audit($user['email'], 'email.retried', 'email', $id, ['count' => $changed]);
        View::setFlash($changed > 0 ? 'ok' : 'warn', $changed > 0
            ? $changed . ' failed address' . ($changed === 1 ? '' : 'es') . ' put back in the queue.'
            : 'Nothing to retry.');
    } elseif ($action === 'cancel') {
        $changed = Repo::cancelMessage($id);
        Repo::audit($user['email'], 'email.cancelled', 'email', $id, ['count' => $changed]);
        View::setFlash('ok', $changed . ' unsent recipient' . ($changed === 1 ? '' : 's') . ' cancelled.');
    } elseif ($action === 'suppress') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Repo::suppress($email, 'unsubscribed', 'Added from message ' . $id, $user['email']);
            Repo::audit($user['email'], 'email.suppressed', 'email', $id, ['email' => $email]);
            View::setFlash('ok', $email . ' will not be written to again.');
        }
    } elseif ($action === 'delete') {
        Auth::requireCan('delete');
        Repo::deleteMessage($id);
        Repo::audit($user['email'], 'email.deleted', 'email', $id, ['subject' => (string) $message['subject']]);
        View::setFlash('ok', 'Message deleted.');
        Util::redirect('messages.php');
    }

    Util::redirect('message.php?id=' . urlencode($id));
}

$message = Repo::getMessage($id);
if ($message === null) {
    View::setFlash('error', 'That message no longer exists.');
    Util::redirect('messages.php');
}

$filter     = (string) ($_GET['show'] ?? 'all');
$recipients = Repo::messageRecipients($id, $filter, 500);
$pending    = (int) $message['total'] - (int) $message['sent_count'] - (int) $message['failed_count'];

View::header('Message', [
    'subtitle' => Util::e((string) $message['subject']),
    'actions'  => '<a class="btn secondary" href="messages.php">Back to messages</a>',
]);
View::flash();
?>

<div class="grid grid-4" style="margin-bottom:18px">
  <div class="stat">
    <div class="label">Recipients</div>
    <div class="value tabular"><?= number_format((int) $message['total']) ?></div>
    <div class="delta"><?= Util::e(Outbox::AUDIENCES[(string) $message['audience']] ?? '') ?></div>
  </div>
  <div class="stat">
    <div class="label">Delivered</div>
    <div class="value tabular"><?= number_format((int) $message['sent_count']) ?></div>
    <div class="delta"><?= (int) $message['total'] > 0
        ? round(((int) $message['sent_count'] / (int) $message['total']) * 100) . '% of the list' : '—' ?></div>
  </div>
  <div class="stat <?= $pending > 0 ? 'attention' : '' ?>">
    <div class="label">Still queued</div>
    <div class="value tabular"><?= number_format(max(0, $pending)) ?></div>
    <div class="delta"><?= $pending > 0 ? 'Not sent yet' : 'Nothing outstanding' ?></div>
  </div>
  <div class="stat">
    <div class="label">Failed</div>
    <div class="value tabular"><?= number_format((int) $message['failed_count']) ?></div>
    <div class="delta"><?= (int) $message['failed_count'] > 0 ? 'Reasons listed below' : 'None' ?></div>
  </div>
</div>

<div class="row between" style="margin-bottom:18px">
  <div class="row">
    <span class="badge <?= Util::e(Outbox::statusTone((string) $message['status'])) ?>">
      <?= Util::e((string) $message['status']) ?></span>
    <span class="small muted">
      Queued by <?= Util::e((string) $message['created_by']) ?>
      on <?= Util::e(Util::formatDate((string) $message['created_at'])) ?>
      <?php if ($message['finished_at']): ?>
        · finished <?= Util::e(Util::relativeTime((string) $message['finished_at'])) ?>
      <?php endif; ?>
    </span>
  </div>

  <div class="row">
    <?php if ($pending > 0): ?>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= Util::e($id) ?>">
        <button type="submit" name="action" value="drain" class="btn">Send the rest</button>
      </form>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= Util::e($id) ?>">
        <label class="xsmall row nowrap" style="gap:5px">
          <input type="checkbox" name="confirm" value="yes" required>
          <button type="submit" name="action" value="cancel" class="btn secondary small">Cancel the rest</button>
        </label>
      </form>
    <?php endif; ?>

    <?php if ((int) $message['failed_count'] > 0): ?>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= Util::e($id) ?>">
        <button type="submit" name="action" value="retry" class="btn secondary">Retry failed</button>
      </form>
    <?php endif; ?>

    <?php if (Auth::can('delete')): ?>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= Util::e($id) ?>">
        <label class="xsmall row nowrap" style="gap:5px">
          <input type="checkbox" name="confirm" value="yes" required>
          <button type="submit" name="action" value="delete" class="btn danger small">Delete</button>
        </label>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Address</th><th>Reference</th><th>Trial</th>
          <th class="tight">Attempts</th><th>Result</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($recipients === []): ?>
        <tr><td colspan="6"><div class="empty"><strong>No recipients to show</strong>
          Nothing matches this filter.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($recipients as $recipient): ?>
        <tr>
          <td>
            <?= Util::e((string) $recipient['email']) ?>
            <?php if ($recipient['error']): ?>
              <div class="xsmall" style="color:var(--serious)"><?= Util::e((string) $recipient['error']) ?></div>
            <?php elseif ($recipient['sent_at']): ?>
              <div class="xsmall muted"><?= Util::e(Util::relativeTime((string) $recipient['sent_at'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="tight mono"><?= Util::e((string) ($recipient['reference'] ?: '—')) ?></td>
          <td class="tight">
            <?= $recipient['trial'] ? View::trialLabel((string) $recipient['trial']) : '<span class="muted">—</span>' ?>
          </td>
          <td class="tight tabular"><?= (int) $recipient['attempts'] ?></td>
          <td class="tight">
            <span class="badge <?= Util::e(Outbox::statusTone((string) $recipient['status'])) ?>">
              <?= Util::e((string) $recipient['status']) ?></span>
          </td>
          <td class="tight">
            <form method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= Util::e($id) ?>">
              <input type="hidden" name="email" value="<?= Util::e((string) $recipient['email']) ?>">
              <button type="submit" name="action" value="suppress" class="btn secondary small"
                      title="Never email this address again">Do not email</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <aside class="stack">
    <section class="card">
      <div class="card-head"><h2>Filter</h2></div>
      <div class="row">
        <?php foreach (['all' => 'Everyone', 'sent' => 'Delivered', 'failed' => 'Failed', 'pending' => 'Queued'] as $key => $label): ?>
          <a class="btn <?= $filter === $key ? '' : 'secondary' ?> small"
             href="message.php?id=<?= Util::e($id) ?>&amp;show=<?= Util::e($key) ?>"><?= Util::e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if (count($recipients) >= 500): ?>
        <p class="xsmall muted" style="margin:12px 0 0">Showing the first 500.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>What was sent</h2></div>
      <?php if ($message['audience_meta'] !== []): ?>
        <p class="xsmall muted" style="margin:0 0 10px">
          Audience:
          <?php foreach ((array) $message['audience_meta'] as $key => $value): ?>
            <?= Util::e((string) $key) ?> =
            <?= Util::e($key === 'trial' && Trials::exists((string) $value)
                ? Trials::name((string) $value) : (string) $value) ?><?= ' ' ?>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
      <div class="preview-frame archive">
        <iframe src="message-preview.php?id=<?= Util::e($id) ?>" title="The message as sent" loading="lazy"></iframe>
      </div>
    </section>
  </aside>
</div>

<?php View::footer(); ?>
