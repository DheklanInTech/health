<?php
/** Overview — what arrived, what still needs a human, and where it came from. */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Repo;
use Olisa\Trials;
use Olisa\Util;
use Olisa\View;

Auth::requireLogin();

$stats = Repo::stats(30);
$recent = Repo::recent(8);

// Week-on-week movement, the number a coordinator actually acts on.
$delta = $stats['prev7'] > 0
    ? round((($stats['last7'] - $stats['prev7']) / $stats['prev7']) * 100)
    : ($stats['last7'] > 0 ? 100 : 0);

$statusData = [];
foreach (Repo::STATUSES as $status) {
    $statusData[Repo::STATUS_LABELS[$status]] = $stats['byStatus'][$status] ?? 0;
}

View::header('Overview', [
    'subtitle' => 'Screening applications across all four trials',
    'actions'  => '<a class="btn secondary" href="submissions.php?status=new">Review queue</a>'
                . '<a class="btn" href="submissions.php">All applications</a>',
]);
View::flash();
?>

<div class="grid grid-4">
  <div class="stat">
    <div class="label">Total applications</div>
    <div class="value tabular"><?= number_format($stats['total']) ?></div>
    <div class="delta">since launch</div>
  </div>

  <div class="stat">
    <div class="label">Last 7 days</div>
    <div class="value tabular"><?= number_format($stats['last7']) ?></div>
    <div class="delta <?= $delta > 0 ? 'up' : ($delta < 0 ? 'down' : '') ?>">
      <?= $delta > 0 ? '+' : '' ?><?= $delta ?>% vs previous 7 days
    </div>
  </div>

  <div class="stat">
    <div class="label">Today</div>
    <div class="value tabular"><?= number_format($stats['today']) ?></div>
    <div class="delta"><?= number_format($stats['last30']) ?> in the last 30 days</div>
  </div>

  <div class="stat<?= $stats['unreviewed'] > 0 ? ' attention' : '' ?>">
    <div class="label">Awaiting review</div>
    <div class="value tabular"><?= number_format($stats['unreviewed']) ?></div>
    <div class="delta">
      <?php if ($stats['unreviewed'] > 0): ?>
        <a href="submissions.php?status=new">Open the queue</a>
      <?php else: ?>
        Queue is clear
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-main" style="margin-top:16px">
  <section class="card">
    <div class="card-head">
      <h2>Applications per day</h2>
      <span class="hint">Last 30 days, stacked by trial</span>
    </div>
    <?= View::timeSeries($stats['daily'], 30) ?>
    <?= View::trialLegend($stats['byTrial']) ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>By status</h2></div>
    <?= View::barList($statusData) ?>
  </section>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <section class="card">
    <div class="card-head">
      <h2>Automated screening</h2>
      <span class="hint">Guidance only — a person decides</span>
    </div>
    <?= View::splitBar([
        ['label' => 'Passes screening', 'value' => $stats['byVerdict']['eligible'] ?? 0, 'color' => '#15803d'],
        ['label' => 'Needs review',     'value' => $stats['byVerdict']['review'] ?? 0,   'color' => '#b45309'],
        ['label' => 'Likely excluded',  'value' => $stats['byVerdict']['excluded'] ?? 0, 'color' => '#b91c1c'],
    ]) ?>
    <p class="xsmall muted" style="margin:14px 0 0">
      Flags come from the protocol rules in <span class="mono">Eligibility.php</span>.
      Nothing is auto-rejected — every application stays in the queue until
      someone sets its status.
    </p>
  </section>

  <section class="card">
    <div class="card-head">
      <h2>By trial</h2>
      <span class="hint">All time</span>
    </div>
    <?php
    $trialData = [];
    foreach (Trials::slugs() as $slug) {
        $trialData[Trials::name($slug)] = $stats['byTrial'][$slug] ?? 0;
    }
    ?>
    <?php foreach (Trials::slugs() as $i => $slug): ?>
      <?php
      $value = $stats['byTrial'][$slug] ?? 0;
      $max = max(1, max($stats['byTrial'] ?: [1]));
      ?>
      <div class="bar-row">
        <span class="k"><?= Util::e(Trials::name($slug)) ?></span>
        <span class="bar-track">
          <span class="bar-fill" style="width:<?= round(($value / $max) * 100, 1) ?>%;background:<?= View::trialColor($slug) ?>"></span>
        </span>
        <span class="n tabular"><?= (int) $value ?></span>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<section class="card" style="margin-top:16px">
  <div class="card-head">
    <h2>Latest applications</h2>
    <a class="small" href="submissions.php">View all</a>
  </div>

  <?php if ($recent === []): ?>
    <div class="empty">
      <strong>No applications yet</strong>
      They will appear here as soon as someone completes a screening form.
    </div>
  <?php else: ?>
    <div class="table-wrap" style="border:0">
      <table class="data">
        <thead>
          <tr>
            <th>Reference</th><th>Trial</th><th>Email</th>
            <th>Screening</th><th>Status</th><th>Received</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $row): ?>
          <tr>
            <td class="tight">
              <a class="row-link mono" href="submission.php?id=<?= Util::e((string) $row['id']) ?>">
                <?= Util::e((string) $row['application_id']) ?>
              </a>
            </td>
            <td class="tight"><?= View::trialLabel((string) $row['trial']) ?></td>
            <td><?= Util::e((string) $row['email']) ?></td>
            <td class="tight"><?= View::verdictBadge((string) $row['verdict']) ?></td>
            <td class="tight"><?= View::statusBadge((string) $row['status']) ?></td>
            <td class="tight muted small" title="<?= Util::e(Util::formatDate((string) $row['created_at'])) ?>">
              <?= Util::e(Util::relativeTime((string) $row['created_at'])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php View::footer(); ?>
