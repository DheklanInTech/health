<?php
/** The applications table: search, filter, sort, paginate, bulk-update, export. */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Csrf;
use Olisa\Repo;
use Olisa\Trials;
use Olisa\Util;
use Olisa\View;

$user = Auth::requireLogin();

// ------------------------------------------------------------- bulk actions

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requirePost();
    Auth::requireCan('edit');

    $ids    = array_values(array_filter((array) ($_POST['ids'] ?? []), 'is_string'));
    $status = (string) ($_POST['bulk_status'] ?? '');

    // "Email selected" hands the picked rows to the composer rather than
    // changing anything here, so the audience is chosen with the same filters
    // the reviewer was already looking at.
    if ((string) ($_POST['bulk_action'] ?? '') === 'email') {
        if ($ids === []) {
            View::setFlash('warn', 'Select at least one application first.');
            Util::redirect('submissions.php' . Util::qs($_GET));
        }
        Auth::start();
        $_SESSION['email_selection'] = $ids;
        Util::redirect('messages.php');
    }

    if ($ids === []) {
        View::setFlash('warn', 'Select at least one application first.');
    } elseif (!in_array($status, Repo::STATUSES, true)) {
        View::setFlash('warn', 'Choose a status to apply.');
    } else {
        $changed = Repo::bulkUpdateStatus($ids, $status);
        Repo::audit($user['email'], 'submission.bulk_status', 'submission', null, [
            'status' => $status,
            'count'  => $changed,
            'ids'    => array_slice($ids, 0, 50),
        ]);
        View::setFlash('ok', $changed . ' application' . ($changed === 1 ? '' : 's')
            . ' set to ' . (Repo::STATUS_LABELS[$status] ?? $status) . '.');
    }

    // POST-redirect-GET so a refresh does not re-apply the change.
    Util::redirect('submissions.php' . Util::qs($_GET));
}

// ------------------------------------------------------------------ filters

$filters = [
    'q'       => trim((string) ($_GET['q'] ?? '')),
    'trial'   => (string) ($_GET['trial'] ?? 'all'),
    'status'  => (string) ($_GET['status'] ?? 'all'),
    'verdict' => (string) ($_GET['verdict'] ?? 'all'),
    'from'    => (string) ($_GET['from'] ?? ''),
    'to'      => (string) ($_GET['to'] ?? ''),
    'sort'    => (string) ($_GET['sort'] ?? 'created_at'),
    'dir'     => (string) ($_GET['dir'] ?? 'desc'),
];

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per'] ?? 25);

$result = Repo::listSubmissions($filters, $page, $perPage);
$hasFilters = $filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== ''
    || !in_array($filters['trial'], ['', 'all'], true)
    || !in_array($filters['status'], ['', 'all'], true)
    || !in_array($filters['verdict'], ['', 'all'], true);

/** Column header that toggles its own sort direction. */
function sortLink(string $key, string $label, array $filters): string
{
    $active = ($filters['sort'] ?? '') === $key;
    $dir    = $active && strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    $arrow  = $active ? ($dir === 'asc' ? ' ▾' : ' ▴') : '';
    $query  = Util::qs(array_merge($_GET, ['sort' => $key, 'dir' => $dir, 'page' => 1]));
    return '<a href="submissions.php' . $query . '">' . Util::e($label) . $arrow . '</a>';
}

$exportQuery = Util::qs(array_merge($filters, ['page' => null, 'per' => null]));

View::header('Applications', [
    'subtitle' => number_format($result['total']) . ' application'
        . ($result['total'] === 1 ? '' : 's') . ($hasFilters ? ' matching your filters' : ''),
    'actions'  => Auth::can('export')
        ? '<a class="btn secondary" href="export.php' . $exportQuery . '">Export CSV</a>'
        : '',
]);
View::flash();
?>

<form class="filters" method="get" action="submissions.php">
  <div class="field" style="flex:1;min-width:210px">
    <label for="q">Search</label>
    <input type="search" id="q" name="q" value="<?= Util::e($filters['q']) ?>"
           placeholder="Reference, email or phone" style="width:100%">
  </div>

  <div class="field">
    <label for="trial">Trial</label>
    <select id="trial" name="trial">
      <option value="all">All trials</option>
      <?php foreach (Trials::slugs() as $slug): ?>
        <option value="<?= Util::e($slug) ?>" <?= $filters['trial'] === $slug ? 'selected' : '' ?>>
          <?= Util::e(Trials::name($slug)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="all">Any status</option>
      <?php foreach (Repo::STATUSES as $status): ?>
        <option value="<?= Util::e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
          <?= Util::e(Repo::STATUS_LABELS[$status]) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="verdict">Screening</label>
    <select id="verdict" name="verdict">
      <option value="all">Any result</option>
      <option value="eligible" <?= $filters['verdict'] === 'eligible' ? 'selected' : '' ?>>Passes screening</option>
      <option value="review"   <?= $filters['verdict'] === 'review' ? 'selected' : '' ?>>Needs review</option>
      <option value="excluded" <?= $filters['verdict'] === 'excluded' ? 'selected' : '' ?>>Likely excluded</option>
    </select>
  </div>

  <div class="field">
    <label for="from">From</label>
    <input type="date" id="from" name="from" value="<?= Util::e($filters['from']) ?>">
  </div>

  <div class="field">
    <label for="to">To</label>
    <input type="date" id="to" name="to" value="<?= Util::e($filters['to']) ?>">
  </div>

  <button type="submit" class="btn">Apply</button>
  <?php if ($hasFilters): ?>
    <a class="btn secondary" href="submissions.php">Clear</a>
  <?php endif; ?>
</form>

<?php if ($result['rows'] === []): ?>
  <div class="table-wrap">
    <div class="empty">
      <strong><?= $hasFilters ? 'Nothing matches those filters' : 'No applications yet' ?></strong>
      <?= $hasFilters
          ? 'Try widening the date range or clearing the search.'
          : 'Applications appear here as soon as someone completes a screening form.' ?>
    </div>
  </div>
<?php else: ?>

<form method="post" action="submissions.php<?= Util::qs($_GET) ?>">
  <?= Csrf::field() ?>

  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <?php if (Auth::can('edit')): ?>
            <th style="width:34px">
              <input type="checkbox" id="check-all" aria-label="Select all rows on this page">
            </th>
          <?php endif; ?>
          <th>Reference</th>
          <th><?= sortLink('trial', 'Trial', $filters) ?></th>
          <th><?= sortLink('email', 'Email', $filters) ?></th>
          <th class="tight"><?= sortLink('age', 'Age', $filters) ?></th>
          <th class="tight">BMI</th>
          <th>Screening</th>
          <th><?= sortLink('status', 'Status', $filters) ?></th>
          <th><?= sortLink('created_at', 'Received', $filters) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $row): ?>
        <?php
        $id = (string) $row['id'];
        $flags = $row['eligibility']['flags'] ?? [];
        $exclusions = array_filter($flags, static fn(array $f): bool => ($f['level'] ?? '') === 'exclusion');
        ?>
        <tr>
          <?php if (Auth::can('edit')): ?>
            <td>
              <input type="checkbox" name="ids[]" value="<?= Util::e($id) ?>"
                     aria-label="Select <?= Util::e((string) $row['application_id']) ?>">
            </td>
          <?php endif; ?>
          <td class="tight">
            <a class="row-link mono" href="submission.php?id=<?= Util::e($id) ?>&amp;<?= Util::e(http_build_query($filters)) ?>">
              <?= Util::e((string) $row['application_id']) ?>
            </a>
          </td>
          <td class="tight"><?= View::trialLabel((string) $row['trial']) ?></td>
          <td>
            <?= Util::e((string) $row['email']) ?>
            <?php if (!empty($row['phone'])): ?>
              <div class="xsmall muted"><?= Util::e((string) $row['phone']) ?></div>
            <?php endif; ?>
          </td>
          <td class="tight tabular"><?= $row['age'] !== null ? (int) $row['age'] : '—' ?></td>
          <td class="tight tabular"><?= $row['bmi'] !== null ? Util::e((string) $row['bmi']) : '—' ?></td>
          <td class="tight">
            <?= View::verdictBadge((string) $row['verdict']) ?>
            <?php if ($exclusions !== []): ?>
              <div class="xsmall muted" title="<?= Util::e(implode('; ', array_column($exclusions, 'label'))) ?>">
                <?= count($exclusions) ?> exclusion<?= count($exclusions) === 1 ? '' : 's' ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="tight"><?= View::statusBadge((string) $row['status']) ?></td>
          <td class="tight muted small" title="<?= Util::e(Util::formatDate((string) $row['created_at'])) ?>">
            <?= Util::e(Util::relativeTime((string) $row['created_at'])) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (Auth::can('edit')): ?>
    <div class="row" style="margin-top:12px">
      <label class="xsmall muted" for="bulk_status">With selected:</label>
      <select id="bulk_status" name="bulk_status">
        <option value="">Set status to…</option>
        <?php foreach (Repo::STATUSES as $status): ?>
          <option value="<?= Util::e($status) ?>"><?= Util::e(Repo::STATUS_LABELS[$status]) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn small">Apply to selected</button>
      <button type="submit" name="bulk_action" value="email" class="btn secondary small"
              formnovalidate>Email selected</button>
    </div>
  <?php endif; ?>
</form>

<div class="pager">
  <div class="info">
    Page <?= $result['page'] ?> of <?= $result['pages'] ?> ·
    <?= number_format($result['total']) ?> total
  </div>
  <div class="links">
    <?php
    $base = static fn(int $p): string => 'submissions.php' . Util::qs(array_merge($_GET, ['page' => $p]));
    ?>
    <?php if ($result['page'] > 1): ?>
      <a href="<?= Util::e($base($result['page'] - 1)) ?>" rel="prev">Previous</a>
    <?php else: ?>
      <span class="disabled">Previous</span>
    <?php endif; ?>

    <?php
    // A short window around the current page keeps the control usable at any size.
    $start = max(1, $result['page'] - 2);
    $end   = min($result['pages'], $start + 4);
    $start = max(1, $end - 4);
    for ($p = $start; $p <= $end; $p++):
    ?>
      <?php if ($p === $result['page']): ?>
        <span class="current" aria-current="page"><?= $p ?></span>
      <?php else: ?>
        <a href="<?= Util::e($base($p)) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($result['page'] < $result['pages']): ?>
      <a href="<?= Util::e($base($result['page'] + 1)) ?>" rel="next">Next</a>
    <?php else: ?>
      <span class="disabled">Next</span>
    <?php endif; ?>
  </div>
</div>

<script src="assets/table.js" defer></script>
<?php endif; ?>

<?php View::footer(); ?>
