<?php
/** One application: every answer, the screening flags, status and notes. */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Csrf;
use Olisa\Eligibility;
use Olisa\Repo;
use Olisa\Trials;
use Olisa\Util;
use Olisa\View;

$user = Auth::requireLogin();

$id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');

// Filters travel with the record so Previous/Next walk the same queue.
$filters = [
    'q'       => (string) ($_GET['q'] ?? ''),
    'trial'   => (string) ($_GET['trial'] ?? 'all'),
    'status'  => (string) ($_GET['status'] ?? 'all'),
    'verdict' => (string) ($_GET['verdict'] ?? 'all'),
    'from'    => (string) ($_GET['from'] ?? ''),
    'to'      => (string) ($_GET['to'] ?? ''),
];
$carry = array_filter($filters, static fn($v) => $v !== '' && $v !== 'all');

// ------------------------------------------------------------------ actions

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requirePost();

    $action  = (string) ($_POST['action'] ?? '');
    $current = Repo::getSubmission($id);

    if ($current === null) {
        View::setFlash('error', 'That application no longer exists.');
        Util::redirect('submissions.php');
    }

    if ($action === 'status') {
        Auth::requireCan('edit');
        $status = (string) ($_POST['status'] ?? '');
        if (Repo::updateStatus((string) $current['id'], $status)) {
            Repo::audit($user['email'], 'submission.status', 'submission', (string) $current['id'], [
                'from' => $current['status'],
                'to'   => $status,
            ]);
            View::setFlash('ok', 'Status set to ' . (Repo::STATUS_LABELS[$status] ?? $status) . '.');
        } else {
            View::setFlash('error', 'That is not a valid status.');
        }
    } elseif ($action === 'note') {
        Auth::requireCan('edit');
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            View::setFlash('warn', 'Write something before saving the note.');
        } else {
            Repo::addNote((string) $current['id'], $user['email'], $body);
            // The note text is not copied into the audit trail — it can contain
            // clinical detail, and the audit log is the widest-read table here.
            Repo::audit($user['email'], 'submission.note_added', 'submission', (string) $current['id']);
            View::setFlash('ok', 'Note added.');
        }
    } elseif ($action === 'delete') {
        Auth::requireCan('delete');
        if (($_POST['confirm'] ?? '') !== 'yes') {
            View::setFlash('warn', 'Tick the confirmation box to delete an application.');
            Util::redirect('submission.php' . Util::qs(array_merge($carry, ['id' => $id])));
        }
        Repo::deleteSubmission((string) $current['id']);
        Repo::audit($user['email'], 'submission.deleted', 'submission', (string) $current['id'], [
            'application_id' => $current['application_id'],
            'trial'          => $current['trial'],
        ]);
        View::setFlash('ok', 'Application ' . $current['application_id'] . ' was deleted.');
        Util::redirect('submissions.php' . Util::qs($carry));
    }

    Util::redirect('submission.php' . Util::qs(array_merge($carry, ['id' => $id])));
}

// -------------------------------------------------------------------- render

$row = Repo::getSubmission($id);
if ($row === null) {
    View::header('Not found');
    echo '<div class="card"><div class="empty"><strong>Application not found</strong>'
       . 'It may have been deleted. <a href="submissions.php">Back to all applications</a>.</div></div>';
    View::footer();
    exit;
}

$trial       = (string) $row['trial'];
$answers     = $row['answers'];
$eligibility = $row['eligibility'];
$flags       = $eligibility['flags'] ?? [];
$adjacent    = Repo::adjacentIds((string) $row['created_at'], $filters);
$history     = Repo::auditFor('submission', (string) $row['id']);

// Answers that actually triggered an exclusion or caution, so the reason shows
// next to the answer rather than only in the summary panel. Derived from the
// rules rather than assuming "Yes" is bad — for "Are You 65 years or Older?"
// the concerning answer is No.
$flaggedFields = Eligibility::flaggedFields($flags);

// Reason text per field, for the tooltip on a marked answer.
$flagReasons = [];
foreach ($flags as $flag) {
    if (!in_array($flag['level'] ?? '', ['exclusion', 'caution'], true)) {
        continue;
    }
    foreach ((array) ($flag['fields'] ?? []) as $field) {
        $flagReasons[(string) $field][] = (string) $flag['label'];
    }
}

$actions = '<a class="btn secondary" href="submissions.php' . Util::e(Util::qs($carry)) . '">Back to list</a>';
if ($adjacent['prev']) {
    $actions .= '<a class="btn secondary small" href="submission.php'
        . Util::e(Util::qs(array_merge($carry, ['id' => $adjacent['prev']]))) . '">← Newer</a>';
}
if ($adjacent['next']) {
    $actions .= '<a class="btn secondary small" href="submission.php'
        . Util::e(Util::qs(array_merge($carry, ['id' => $adjacent['next']]))) . '">Older →</a>';
}

View::header((string) $row['application_id'], [
    'subtitle' => Trials::name($trial) . ' · received ' . Util::formatDate((string) $row['created_at']),
    'actions'  => $actions,
]);
View::flash();
?>

<div class="detail-grid">
  <div class="stack">

    <section class="card answers">
      <div class="card-head">
        <h2>Screening answers</h2>
        <span class="hint"><?= count($answers) ?> questions</span>
      </div>

      <?php foreach (Trials::steps($trial) as $step): ?>
        <div class="step-title"><?= Util::e((string) $step['label']) ?></div>
        <dl>
          <?php foreach ($step['fields'] as $field): ?>
            <?php
            $name  = (string) $field['name'];
            $value = $answers[$name] ?? '';
            $isFlagged = in_array($name, $flaggedFields, true);
            $reason = $isFlagged ? implode('; ', $flagReasons[$name] ?? []) : '';
            ?>
            <dt><?= Util::e((string) $field['label']) ?></dt>
            <dd class="<?= $isFlagged ? 'flagged' : '' ?>"
                <?= $reason !== '' ? 'title="' . Util::e($reason) . '"' : '' ?>>
              <?= $value === '' ? '<span class="muted">Not answered</span>' : Util::e((string) $value) ?>
            </dd>
          <?php endforeach; ?>
        </dl>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <h2>Notes</h2>
        <span class="hint"><?= count($row['notes']) ?> note<?= count($row['notes']) === 1 ? '' : 's' ?></span>
      </div>

      <?php if ($row['notes'] === []): ?>
        <p class="muted small" style="margin-top:0">
          No notes yet. Use these to record contact attempts, screening calls and outcomes.
        </p>
      <?php else: ?>
        <?php foreach ($row['notes'] as $note): ?>
          <div class="note">
            <div class="meta">
              <strong><?= Util::e((string) $note['author_email']) ?></strong>
              · <?= Util::e(Util::formatDate((string) $note['created_at'])) ?>
            </div>
            <div class="body"><?= Util::e((string) $note['body']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (Auth::can('edit')): ?>
        <form method="post" style="margin-top:14px">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="note">
          <input type="hidden" name="id" value="<?= Util::e((string) $row['id']) ?>">
          <label class="sr-only" for="body">Add a note</label>
          <textarea id="body" name="body" placeholder="Called and left a voicemail; will retry Thursday." required></textarea>
          <button type="submit" class="btn small" style="margin-top:8px">Add note</button>
        </form>
      <?php endif; ?>
    </section>

    <?php if ($history !== []): ?>
      <section class="card">
        <div class="card-head"><h2>History</h2></div>
        <?php foreach ($history as $event): ?>
          <div class="note">
            <div class="meta">
              <strong><?= Util::e((string) $event['actor_email']) ?></strong>
              · <?= Util::e(Util::formatDate((string) $event['created_at'])) ?>
            </div>
            <div class="body small">
              <?= Util::e(match ((string) $event['action']) {
                  'submission.created'     => 'Application received from the public form',
                  'submission.status'      => 'Changed the status',
                  'submission.bulk_status' => 'Changed the status in a bulk update',
                  'submission.note_added'  => 'Added a note',
                  default => ucfirst(str_replace(['submission.', '_'], ['', ' '], (string) $event['action'])),
              }) ?>
              <?php if (!empty($event['meta']['from']) && !empty($event['meta']['to'])): ?>
                — <?= Util::e((string) $event['meta']['from']) ?> → <strong><?= Util::e((string) $event['meta']['to']) ?></strong>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>

  <div class="stack">

    <section class="card">
      <div class="card-head"><h2>Status</h2></div>
      <?php if (Auth::can('edit')): ?>
        <form method="post" class="status-form">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= Util::e((string) $row['id']) ?>">
          <label class="sr-only" for="status">Status</label>
          <select id="status" name="status" style="flex:1">
            <?php foreach (Repo::STATUSES as $status): ?>
              <option value="<?= Util::e($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>>
                <?= Util::e(Repo::STATUS_LABELS[$status]) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn small">Save</button>
        </form>
      <?php else: ?>
        <?= View::statusBadge((string) $row['status']) ?>
      <?php endif; ?>
      <p class="xsmall muted" style="margin:12px 0 0">
        Last updated <?= Util::e(Util::relativeTime((string) $row['updated_at'])) ?>
      </p>
    </section>

    <section class="card">
      <div class="card-head">
        <h2>Automated screening</h2>
      </div>
      <?= View::verdictBadge((string) $row['verdict']) ?>

      <?php if ($row['bmi'] !== null): ?>
        <p class="small" style="margin:12px 0 0">
          <strong>BMI <?= Util::e((string) $row['bmi']) ?></strong>
          <span class="muted">· <?= Util::e(Eligibility::bmiCategory((float) $row['bmi'])) ?></span>
        </p>
      <?php endif; ?>

      <?php if ($flags === []): ?>
        <p class="small muted" style="margin:12px 0 0">No flags raised by the protocol rules.</p>
      <?php else: ?>
        <ul class="flag-list" style="margin-top:12px">
          <?php foreach ($flags as $flag): ?>
            <li>
              <span class="dot <?= Util::e((string) $flag['level']) ?>"></span>
              <span><?= Util::e((string) $flag['label']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <p class="xsmall muted" style="margin:14px 0 0">
        Guidance only. Nothing is rejected automatically — the status above is
        set by a person.
      </p>
    </section>

    <section class="card">
      <div class="card-head"><h2>Contact</h2></div>
      <p class="small" style="margin:0 0 6px">
        <a href="mailto:<?= Util::e((string) $row['email']) ?>"><?= Util::e((string) $row['email']) ?></a>
      </p>
      <?php if (!empty($row['phone'])): ?>
        <p class="small" style="margin:0 0 6px">
          <a href="tel:<?= Util::e(preg_replace('/[^0-9+]/', '', (string) $row['phone']) ?? '') ?>">
            <?= Util::e((string) $row['phone']) ?>
          </a>
        </p>
      <?php endif; ?>
      <p class="xsmall muted" style="margin:10px 0 0">
        Reference <span class="mono"><?= Util::e((string) $row['application_id']) ?></span><br>
        Submitted from a connection we record only as a one-way hash.
      </p>
    </section>

    <?php if (Auth::can('delete')): ?>
      <section class="card">
        <div class="card-head"><h2>Delete</h2></div>
        <p class="xsmall muted" style="margin:0 0 10px">
          Permanently removes this application and its notes. Use it for a
          duplicate, a test record, or an erasure request. This cannot be undone.
        </p>
        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= Util::e((string) $row['id']) ?>">
          <!-- A required checkbox rather than a JS confirm(): the dashboard's
               Content-Security-Policy forbids inline handlers, and this is
               also clearer about what is being agreed to. -->
          <label class="xsmall row" style="gap:7px;margin-bottom:10px">
            <input type="checkbox" name="confirm" value="yes" required>
            <span>Yes, permanently delete <?= Util::e((string) $row['application_id']) ?></span>
          </label>
          <button type="submit" class="btn danger small">Delete application</button>
        </form>
      </section>
    <?php endif; ?>
  </div>
</div>

<?php View::footer(); ?>
