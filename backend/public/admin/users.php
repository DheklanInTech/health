<?php
/** Team management. Owner-only: this is the page that hands out access. */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Csrf;
use Olisa\Repo;
use Olisa\Util;
use Olisa\View;

$user = Auth::requireLogin();
Auth::requireCan('users');

const ROLES = [
    'owner'  => 'Owner — full access, including team and deletion',
    'admin'  => 'Admin — review applications, add notes, export',
    'viewer' => 'Viewer — read only, no changes and no export',
];

$generated = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::requirePost();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $name  = trim((string) ($_POST['name'] ?? ''));
        $role  = (string) ($_POST['role'] ?? 'viewer');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            View::setFlash('error', 'Enter a valid email address.');
        } elseif ($name === '') {
            View::setFlash('error', 'Enter a name.');
        } elseif (!array_key_exists($role, ROLES)) {
            View::setFlash('error', 'Choose a role.');
        } elseif (Repo::userByEmail($email) !== null) {
            View::setFlash('error', 'An account with that email already exists.');
        } else {
            // A generated password shown once, and a forced change at first
            // sign-in, so no one but the new user ever knows their password.
            $password = bin2hex(random_bytes(9));
            $id = Repo::createUser($email, $name, $role, password_hash($password, PASSWORD_DEFAULT), true);
            Repo::audit($user['email'], 'user.created', 'user', $id, ['email' => $email, 'role' => $role]);
            Auth::start();
            $_SESSION['generated_password'] = ['email' => $email, 'password' => $password];
            View::setFlash('ok', 'Account created for ' . $email . '.');
        }
    } elseif ($action === 'update') {
        $id     = (string) ($_POST['id'] ?? '');
        $target = Repo::userById($id);

        if ($target === null) {
            View::setFlash('error', 'That account no longer exists.');
        } else {
            $role     = (string) ($_POST['role'] ?? $target['role']);
            $disabled = isset($_POST['disabled']) ? 1 : 0;

            // Never let the last active owner be demoted or switched off —
            // that would lock everyone out of team management permanently.
            $isLastOwner = $target['role'] === 'owner' && Repo::countOwners() <= 1;
            if ($isLastOwner && ($role !== 'owner' || $disabled === 1)) {
                View::setFlash('error', 'This is the only owner account. Promote someone else first.');
            } elseif (!array_key_exists($role, ROLES)) {
                View::setFlash('error', 'Choose a valid role.');
            } else {
                Repo::updateUser($id, ['role' => $role, 'disabled' => $disabled]);
                Repo::audit($user['email'], 'user.updated', 'user', $id, [
                    'email' => $target['email'], 'role' => $role, 'disabled' => $disabled,
                ]);
                View::setFlash('ok', 'Updated ' . $target['email'] . '.');
            }
        }
    } elseif ($action === 'delete') {
        $id     = (string) ($_POST['id'] ?? '');
        $target = Repo::userById($id);

        if ($target === null) {
            View::setFlash('error', 'That account no longer exists.');
        } elseif ($id === $user['id']) {
            View::setFlash('error', 'You cannot remove your own account.');
        } elseif ($target['role'] === 'owner' && Repo::countOwners() <= 1) {
            View::setFlash('error', 'This is the only owner account.');
        } else {
            Repo::deleteUser($id);
            Repo::audit($user['email'], 'user.deleted', 'user', $id, ['email' => $target['email']]);
            View::setFlash('ok', 'Removed ' . $target['email'] . '.');
        }
    }

    Util::redirect('users.php');
}

Auth::start();
if (!empty($_SESSION['generated_password'])) {
    $generated = $_SESSION['generated_password'];
    unset($_SESSION['generated_password']);
}

$users = Repo::listUsers();

View::header('Team', ['subtitle' => count($users) . ' account' . (count($users) === 1 ? '' : 's')]);
View::flash();
?>

<?php if ($generated !== null): ?>
  <div class="alert ok">
    <strong>One-time password for <?= Util::e((string) $generated['email']) ?>:</strong>
    <span class="mono" style="font-size:16px"><?= Util::e((string) $generated['password']) ?></span><br>
    <span class="small">Send it to them over a channel other than email if you can. It is shown
    only once, and they will be asked to choose their own password at first sign-in.</span>
  </div>
<?php endif; ?>

<div class="grid grid-main">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Person</th><th>Role</th><th>Last sign-in</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $account): ?>
        <?php $isSelf = $account['id'] === $user['id']; ?>
        <tr>
          <td>
            <strong><?= Util::e((string) $account['name']) ?></strong>
            <?php if ($isSelf): ?><span class="badge accent" style="margin-left:6px">You</span><?php endif; ?>
            <div class="xsmall muted"><?= Util::e((string) $account['email']) ?></div>
          </td>
          <td class="tight">
            <form method="post" class="row" style="gap:6px">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= Util::e((string) $account['id']) ?>">
              <label class="sr-only" for="role-<?= Util::e((string) $account['id']) ?>">Role</label>
              <select id="role-<?= Util::e((string) $account['id']) ?>" name="role">
                <?php foreach (ROLES as $value => $label): ?>
                  <option value="<?= Util::e($value) ?>" <?= $account['role'] === $value ? 'selected' : '' ?>>
                    <?= Util::e(ucfirst($value)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <label class="xsmall row" style="gap:5px">
                <input type="checkbox" name="disabled" value="1" <?= (int) $account['disabled'] === 1 ? 'checked' : '' ?>>
                Disabled
              </label>
              <button type="submit" class="btn small secondary">Save</button>
            </form>
          </td>
          <td class="tight muted small">
            <?= $account['last_login_at'] ? Util::e(Util::relativeTime((string) $account['last_login_at'])) : 'Never' ?>
          </td>
          <td class="tight">
            <?php if ((int) $account['disabled'] === 1): ?>
              <span class="badge serious">Disabled</span>
            <?php elseif ((int) $account['must_change'] === 1): ?>
              <span class="badge warn">Password pending</span>
            <?php else: ?>
              <span class="badge good">Active</span>
            <?php endif; ?>
          </td>
          <td class="tight">
            <?php if (!$isSelf): ?>
              <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= Util::e((string) $account['id']) ?>">
                <label class="xsmall row nowrap" style="gap:5px">
                  <input type="checkbox" name="confirm" value="yes" required>
                  <button type="submit" class="btn danger small">Remove</button>
                </label>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <section class="card">
    <div class="card-head"><h2>Add someone</h2></div>
    <form method="post" class="stack">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create">

      <div class="field">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required style="width:100%">
      </div>

      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required style="width:100%">
      </div>

      <div class="field">
        <label for="new-role">Role</label>
        <select id="new-role" name="role" style="width:100%">
          <?php foreach (ROLES as $value => $label): ?>
            <option value="<?= Util::e($value) ?>" <?= $value === 'viewer' ? 'selected' : '' ?>>
              <?= Util::e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn">Create account</button>
    </form>

    <p class="xsmall muted" style="margin-bottom:0">
      Give people the narrowest role that lets them do their job. Viewers cannot
      export, which matters — an export is a copy of health answers leaving the
      system.
    </p>
  </section>
</div>

<?php View::footer(); ?>
