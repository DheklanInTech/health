<?php
/**
 * Compose and send email to applicants — one address or a whole audience.
 *
 * The draft lives in the session, not in a hidden field, so the live preview on
 * the right can be rendered server-side from exactly the same data the send
 * will use. What you see previewed is what goes out.
 */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Config;
use Olisa\Csrf;
use Olisa\EmailTemplate;
use Olisa\Mailer;
use Olisa\Outbox;
use Olisa\Repo;
use Olisa\Trials;
use Olisa\Util;
use Olisa\View;

$user = Auth::requireLogin();
Auth::requireCan('edit');
Auth::start();

const DRAFT_KEY = 'email_draft';

/** @return array<string, mixed> */
function draft(): array
{
    $stored = $_SESSION[DRAFT_KEY] ?? [];
    return array_merge([
        'subject'   => '',
        'preheader' => '',
        'body'      => '',
        'cta_label' => '',
        'cta_url'   => '',
        'audience'  => 'single',
        'addresses' => '',
        'name'      => '',
        'trial'     => 'all',
        'status'    => 'all',
        'verdict'   => 'all',
        'from'      => '',
        'to'        => '',
        'ids'       => [],
    ], is_array($stored) ? $stored : []);
}

/** @return array<string, mixed> */
function draftFromPost(array $current): array
{
    return [
        'subject'   => mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 200),
        'preheader' => mb_substr(trim((string) ($_POST['preheader'] ?? '')), 0, 200),
        'body'      => mb_substr((string) ($_POST['body'] ?? ''), 0, 20000),
        'cta_label' => mb_substr(trim((string) ($_POST['cta_label'] ?? '')), 0, 120),
        'cta_url'   => mb_substr(trim((string) ($_POST['cta_url'] ?? '')), 0, 512),
        'audience'  => array_key_exists((string) ($_POST['audience'] ?? ''), Outbox::AUDIENCES)
            ? (string) $_POST['audience'] : 'single',
        'addresses' => mb_substr(trim((string) ($_POST['addresses'] ?? '')), 0, 20000),
        'name'      => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120),
        'trial'     => (string) ($_POST['trial'] ?? 'all'),
        'status'    => (string) ($_POST['status'] ?? 'all'),
        'verdict'   => (string) ($_POST['verdict'] ?? 'all'),
        'from'      => (string) ($_POST['from'] ?? ''),
        'to'        => (string) ($_POST['to'] ?? ''),
        // Not a form field: the selection is carried over from the
        // applications page and must survive an edit to anything else.
        'ids'       => $current['ids'],
    ];
}

// A handoff from the applications page: "Email selected".
if (!empty($_SESSION['email_selection']) && is_array($_SESSION['email_selection'])) {
    $current = draft();
    $current['ids'] = array_values(array_filter($_SESSION['email_selection'], 'is_string'));
    $current['audience'] = 'selection';
    $_SESSION[DRAFT_KEY] = $current;
    unset($_SESSION['email_selection']);
    Util::redirect('messages.php');
}

// ------------------------------------------------------------------ actions

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requirePost();
    $action = (string) ($_POST['action'] ?? 'preview');

    if ($action === 'discard') {
        unset($_SESSION[DRAFT_KEY]);
        View::setFlash('ok', 'Draft cleared.');
        Util::redirect('messages.php');
    }

    $_SESSION[DRAFT_KEY] = draftFromPost(draft());
    $message = $_SESSION[DRAFT_KEY];

    if ($action === 'send') {
        $resolved = Outbox::resolveAudience((string) $message['audience'], $message);
        $errors   = Outbox::validate($message, count($resolved['recipients']));

        $bulk = count($resolved['recipients']) > 1;
        if ($errors === [] && $bulk && ($_POST['confirm'] ?? '') !== 'yes') {
            $errors[] = 'Tick the confirmation box — this goes to '
                . number_format(count($resolved['recipients'])) . ' people.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                View::setFlash('error', $error);
            }
            Util::redirect('messages.php');
        }

        $message['audience_meta'] = $resolved['meta'];
        $messageId = Outbox::queue($message, $resolved['recipients'], $user['email']);

        Repo::audit($user['email'], 'email.queued', 'email', $messageId, [
            'subject'    => $message['subject'],
            'audience'   => $message['audience'],
            'recipients' => count($resolved['recipients']),
            'suppressed' => $resolved['suppressed'],
        ]);

        // Send what fits in this request; the rest waits for the queue page or
        // for cron. Better a visible remainder than a timed-out request that
        // leaves nobody knowing how far it got.
        $budget = max(5, Config::int('mail.request_budget', 15));
        $result = Outbox::drain($messageId, Config::int('mail.batch_size', 25), microtime(true) + $budget);

        unset($_SESSION[DRAFT_KEY]);

        if ($result['aborted'] !== null && $result['sent'] === 0) {
            View::setFlash('error', 'Nothing could be sent: ' . $result['aborted']);
        } elseif ($result['remaining'] > 0) {
            View::setFlash('ok', $result['sent'] . ' sent so far. '
                . $result['remaining'] . ' still queued — keep this page open and use “Send the rest”.');
        } else {
            View::setFlash('ok', $result['sent'] . ' message' . ($result['sent'] === 1 ? '' : 's') . ' sent.');
        }
        if ($result['failed'] > 0) {
            View::setFlash('warn', $result['failed'] . ' address'
                . ($result['failed'] === 1 ? '' : 'es') . ' failed — see the delivery list below.');
        }

        Util::redirect('message.php?id=' . urlencode($messageId));
    }

    Util::redirect('messages.php');
}

// ------------------------------------------------------------------- render

$draft    = draft();
$resolved = Outbox::resolveAudience((string) $draft['audience'], $draft);
$count    = count($resolved['recipients']);
$history  = Repo::listMessages(1, 10);
$transport = Mailer::usesSmtp() ? 'SMTP · ' . Config::str('mail.smtp_host') : 'PHP mail()';

View::header('Messages', [
    'subtitle' => 'Compose an email to one applicant or to an audience',
    'actions'  => '<a class="btn secondary" href="suppressions.php">Do-not-email list</a>',
]);
View::flash();
?>

<?php if (Config::str('mail.from') === ''): ?>
  <div class="alert error">
    <strong>No sending address is configured.</strong> Set <code>mail.from</code> in
    <code>config.php</code> before sending anything — messages will be rejected without it.
  </div>
<?php elseif (!Mailer::usesSmtp()): ?>
  <div class="alert warn">
    <strong>Sending through PHP <code>mail()</code>.</strong> That is fine for a test and
    unreliable for real applicants — shared-hosting mail lands in spam and its failures are
    invisible. Configure <code>mail.smtp_*</code> in <code>config.php</code> first.
  </div>
<?php endif; ?>

<form method="post" action="messages.php" class="compose">
  <?= Csrf::field() ?>

  <div class="compose-grid">
    <!-- --------------------------------------------------------- composer -->
    <div class="stack">
      <section class="card">
        <div class="card-head">
          <h2>Who it goes to</h2>
          <span class="hint"><?= number_format($count) ?> recipient<?= $count === 1 ? '' : 's' ?></span>
        </div>

        <div class="audience-picker">
          <?php
          $options = Outbox::AUDIENCES;
          if ($draft['ids'] === []) {
              unset($options['selection']);
          }
          foreach ($options as $value => $label):
          ?>
            <label class="pick <?= $draft['audience'] === $value ? 'on' : '' ?>">
              <input type="radio" name="audience" value="<?= Util::e($value) ?>"
                     <?= $draft['audience'] === $value ? 'checked' : '' ?>>
              <span><?= Util::e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="audience-panel" data-audience="single manual">
          <div class="field">
            <label for="addresses">Email address<?= $draft['audience'] === 'manual' ? 'es' : '' ?></label>
            <textarea id="addresses" name="addresses" rows="3"
                      placeholder="someone@example.org, another@example.org"><?= Util::e((string) $draft['addresses']) ?></textarea>
            <span class="xsmall muted">Separate several with commas, spaces or new lines.</span>
          </div>
          <div class="field">
            <label for="name">Their name (optional)</label>
            <input type="text" id="name" name="name" value="<?= Util::e((string) $draft['name']) ?>"
                   placeholder="Used by {{name}}" style="width:100%">
          </div>
        </div>

        <div class="audience-panel" data-audience="filter">
          <div class="row" style="gap:10px;align-items:flex-end">
            <div class="field">
              <label for="trial">Trial</label>
              <select id="trial" name="trial">
                <option value="all">All trials</option>
                <?php foreach (Trials::slugs() as $slug): ?>
                  <option value="<?= Util::e($slug) ?>" <?= $draft['trial'] === $slug ? 'selected' : '' ?>>
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
                  <option value="<?= Util::e($status) ?>" <?= $draft['status'] === $status ? 'selected' : '' ?>>
                    <?= Util::e(Repo::STATUS_LABELS[$status]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="verdict">Screening</label>
              <select id="verdict" name="verdict">
                <option value="all">Any result</option>
                <option value="eligible" <?= $draft['verdict'] === 'eligible' ? 'selected' : '' ?>>Passes screening</option>
                <option value="review"   <?= $draft['verdict'] === 'review' ? 'selected' : '' ?>>Needs review</option>
                <option value="excluded" <?= $draft['verdict'] === 'excluded' ? 'selected' : '' ?>>Likely excluded</option>
              </select>
            </div>
            <div class="field">
              <label for="from">From</label>
              <input type="date" id="from" name="from" value="<?= Util::e((string) $draft['from']) ?>">
            </div>
            <div class="field">
              <label for="to">To</label>
              <input type="date" id="to" name="to" value="<?= Util::e((string) $draft['to']) ?>">
            </div>
          </div>
          <p class="xsmall muted" style="margin:10px 0 0">
            One message per person. Someone who applied to two trials is written to once,
            carrying their most recent reference.
          </p>
        </div>

        <div class="audience-panel" data-audience="selection">
          <p class="small" style="margin:0">
            <strong><?= count((array) $draft['ids']) ?></strong> application<?= count((array) $draft['ids']) === 1 ? '' : 's' ?>
            picked on the Applications page.
          </p>
        </div>

        <?php if ($resolved['invalid'] !== []): ?>
          <div class="alert warn" style="margin:14px 0 0">
            Not valid addresses, and skipped:
            <?= Util::e(implode(', ', array_slice($resolved['invalid'], 0, 8))) ?>
          </div>
        <?php endif; ?>
        <?php if ($resolved['suppressed'] > 0): ?>
          <div class="alert info" style="margin:14px 0 0">
            <?= (int) $resolved['suppressed'] ?> address<?= $resolved['suppressed'] === 1 ? '' : 'es' ?>
            on the do-not-email list <?= $resolved['suppressed'] === 1 ? 'was' : 'were' ?> removed.
          </div>
        <?php endif; ?>
      </section>

      <section class="card">
        <div class="card-head">
          <h2>The message</h2>
          <span class="hint">Preview updates when you save the draft</span>
        </div>

        <div class="field">
          <label for="subject">Subject</label>
          <input type="text" id="subject" name="subject" maxlength="200" required
                 value="<?= Util::e((string) $draft['subject']) ?>" style="width:100%"
                 placeholder="Next steps for your application">
          <span class="xsmall muted">Most inboxes show about 60 characters.</span>
        </div>

        <div class="field" style="margin-top:14px">
          <label for="preheader">Preview line</label>
          <input type="text" id="preheader" name="preheader" maxlength="200"
                 value="<?= Util::e((string) $draft['preheader']) ?>" style="width:100%"
                 placeholder="The grey line the inbox shows after the subject">
        </div>

        <div class="field" style="margin-top:14px">
          <label for="body">Message</label>
          <textarea id="body" name="body" rows="14" required
                    placeholder="Hello {{name}},&#10;&#10;..."><?= Util::e((string) $draft['body']) ?></textarea>
        </div>

        <details class="help">
          <summary>Formatting and merge fields</summary>
          <div class="help-body">
            <p class="xsmall muted">
              Blank line between paragraphs. <code>## Heading</code> · <code>- bullet</code> ·
              <code>&gt; quote</code> · <code>---</code> for a divider ·
              <code>**bold**</code> · <code>[label](https://…)</code>.
            </p>
            <table class="data" style="font-size:13px">
              <tbody>
              <?php foreach (EmailTemplate::MERGE_FIELDS as $field => $meaning): ?>
                <tr>
                  <td class="tight mono"><?= Util::e($field) ?></td>
                  <td class="muted"><?= Util::e($meaning) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <p class="xsmall muted" style="margin-bottom:0">
              The forms do not ask for a name, so <code>{{name}}</code> reads “there” unless
              you type one for a single send.
            </p>
          </div>
        </details>

        <div class="cta-pair">
          <div class="field">
            <label for="cta_label">Button label (optional)</label>
            <input type="text" id="cta_label" name="cta_label" maxlength="120"
                   value="<?= Util::e((string) $draft['cta_label']) ?>"
                   placeholder="Book your screening call">
          </div>
          <div class="field">
            <label for="cta_url">Button link</label>
            <input type="url" id="cta_url" name="cta_url" maxlength="512"
                   value="<?= Util::e((string) $draft['cta_url']) ?>"
                   placeholder="https://…">
          </div>
        </div>
      </section>

      <section class="card send-box">
        <div class="card-head"><h2>Send</h2><span class="hint">Sending as <?= Util::e($transport) ?></span></div>

        <p class="small" style="margin:0 0 12px">
          This will be delivered to
          <strong><?= number_format($count) ?></strong> recipient<?= $count === 1 ? '' : 's' ?>,
          one message each. No one sees anyone else's address.
        </p>

        <?php if ($count > 1): ?>
          <label class="row small confirm-row" style="gap:8px;align-items:flex-start;margin-bottom:12px">
            <input type="checkbox" name="confirm" value="yes" style="margin-top:3px">
            <span>I have read the preview and want to send this to
              <strong><?= number_format($count) ?></strong> people.</span>
          </label>
        <?php endif; ?>

        <div class="row">
          <button type="submit" name="action" value="send" class="btn"
                  <?= $count === 0 ? 'disabled' : '' ?>>
            Send <?= $count === 1 ? 'message' : 'to ' . number_format($count) . ' people' ?>
          </button>
          <button type="submit" name="action" value="preview" class="btn secondary">Save draft &amp; refresh preview</button>
          <button type="submit" name="action" value="discard" class="btn secondary small">Discard</button>
        </div>
      </section>
    </div>

    <!-- ----------------------------------------------------------- preview -->
    <aside class="preview-pane">
      <div class="card">
        <div class="card-head">
          <h2>Preview</h2>
          <div class="viewport-toggle" id="viewport-toggle">
            <button type="button" class="vp on" data-width="full">Desktop</button>
            <button type="button" class="vp" data-width="phone">Phone</button>
          </div>
        </div>

        <?php if (trim((string) $draft['body']) === '' && trim((string) $draft['subject']) === ''): ?>
          <div class="empty" style="padding:38px 16px">
            <strong>Nothing to preview yet</strong>
            Write a subject and a message, then save the draft.
          </div>
        <?php else: ?>
          <div class="preview-meta">
            <div><span class="k">To</span> <?= Util::e($resolved['recipients'][0]['email'] ?? $user['email']) ?><?php
              if ($count > 1): ?> <span class="muted">and <?= number_format($count - 1) ?> more</span>
              <?php elseif ($count === 0): ?> <span class="muted">— a stand-in, nobody matches yet</span>
              <?php endif; ?></div>
            <div><span class="k">From</span> <?= Util::e(Config::str('mail.from_name') ?: Config::str('app.name')) ?>
              &lt;<?= Util::e(Config::str('mail.from') ?: 'not configured') ?>&gt;</div>
            <div><span class="k">Subject</span> <strong><?= Util::e(EmailTemplate::merge((string) $draft['subject'],
              Outbox::previewRecipient($resolved['recipients'], $user['email']))) ?></strong></div>
          </div>
          <div class="preview-frame" id="preview-frame">
            <iframe src="message-preview.php" title="Email preview" loading="lazy"></iframe>
          </div>
          <p class="xsmall muted" style="margin:10px 0 0">
            Rendered from the saved draft with the first recipient's details merged in.
            The phone view is the same message at 380px — the template reflows below 620px.
          </p>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</form>

<!-- ------------------------------------------------------------- history -->
<section style="margin-top:26px">
  <div class="card-head"><h2>Sent recently</h2>
    <span class="hint"><?= number_format($history['total']) ?> message<?= $history['total'] === 1 ? '' : 's' ?> in total</span>
  </div>

  <?php if ($history['rows'] === []): ?>
    <div class="table-wrap"><div class="empty">
      <strong>Nothing sent yet</strong>
      Messages you send appear here with their delivery result.
    </div></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr><th>Subject</th><th>Audience</th><th class="tight">Recipients</th>
              <th class="tight">Delivered</th><th>State</th><th>Sent by</th><th>When</th></tr>
        </thead>
        <tbody>
        <?php foreach ($history['rows'] as $row): ?>
          <tr>
            <td><a class="row-link" href="message.php?id=<?= Util::e((string) $row['id']) ?>">
              <?= Util::e((string) $row['subject']) ?></a></td>
            <td class="tight muted small"><?= Util::e(Outbox::AUDIENCES[(string) $row['audience']] ?? (string) $row['audience']) ?></td>
            <td class="tight tabular"><?= number_format((int) $row['total']) ?></td>
            <td class="tight tabular">
              <?= number_format((int) $row['sent_count']) ?>
              <?php if ((int) $row['failed_count'] > 0): ?>
                <span class="badge serious" style="margin-left:6px"><?= (int) $row['failed_count'] ?> failed</span>
              <?php endif; ?>
            </td>
            <td class="tight"><span class="badge <?= Util::e(Outbox::statusTone((string) $row['status'])) ?>">
              <?= Util::e((string) $row['status']) ?></span></td>
            <td class="tight muted small"><?= Util::e((string) $row['created_by']) ?></td>
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

<script src="assets/compose.js" defer></script>
<?php View::footer(); ?>
