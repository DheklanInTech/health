<?php
/** Change your own password. Also the forced stop for a first sign-in. */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Csrf;
use Olisa\Repo;
use Olisa\Util;
use Olisa\View;

$user = Auth::requireLogin();
$forced = isset($_GET['forced']) || $user['must_change'];

const MIN_PASSWORD = 12;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requirePost();

    $current = (string) ($_POST['current'] ?? '');
    $next    = (string) ($_POST['next'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    $record = Repo::userById((string) $user['id']);

    if ($record === null) {
        Auth::logout();
        Util::redirect('login.php');
    } elseif (!password_verify($current, (string) $record['password_hash'])) {
        View::setFlash('error', 'Your current password is not correct.');
    } elseif (mb_strlen($next) < MIN_PASSWORD) {
        View::setFlash('error', 'Your new password must be at least ' . MIN_PASSWORD . ' characters.');
    } elseif ($next !== $confirm) {
        View::setFlash('error', 'The two new passwords do not match.');
    } elseif ($next === $current) {
        View::setFlash('error', 'Choose a password you have not used here before.');
    } else {
        Repo::updateUser((string) $user['id'], [
            'password_hash' => password_hash($next, PASSWORD_DEFAULT),
            'must_change'   => 0,
        ]);
        Repo::audit($user['email'], 'user.password_changed', 'user', (string) $user['id']);

        // A password change should not leave old sessions usable. The current
        // session is re-established; anything else dies with its session file.
        Auth::start();
        session_regenerate_id(true);
        $_SESSION['must_change'] = false;

        View::setFlash('ok', 'Your password has been changed.');
        Util::redirect('index.php');
    }

    Util::redirect('password.php' . ($forced ? '?forced=1' : ''));
}

View::header('My password', ['subtitle' => Util::e((string) $user['email'])]);
View::flash();
?>

<?php if ($forced): ?>
  <div class="alert warn">
    <strong>Choose your own password before continuing.</strong>
    You are signed in with a password someone else generated for you.
  </div>
<?php endif; ?>

<div style="max-width:440px">
  <section class="card">
    <form method="post" class="stack">
      <?= Csrf::field() ?>

      <div class="field">
        <label for="current">Current password</label>
        <input type="password" id="current" name="current" required
               autocomplete="current-password" style="width:100%">
      </div>

      <div class="field">
        <label for="next">New password</label>
        <input type="password" id="next" name="next" required minlength="<?= MIN_PASSWORD ?>"
               autocomplete="new-password" style="width:100%">
      </div>

      <div class="field">
        <label for="confirm">Repeat new password</label>
        <input type="password" id="confirm" name="confirm" required minlength="<?= MIN_PASSWORD ?>"
               autocomplete="new-password" style="width:100%">
      </div>

      <button type="submit" class="btn">Change password</button>
    </form>

    <p class="xsmall muted" style="margin-bottom:0">
      At least <?= MIN_PASSWORD ?> characters. A few unrelated words are both
      stronger and easier to remember than a short string of symbols — and
      because this dashboard holds health information, use one you use nowhere
      else.
    </p>
  </section>
</div>

<?php View::footer(); ?>
