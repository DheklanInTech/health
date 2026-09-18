<?php
/** Sign-in. The only page under /admin reachable without a session. */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Csrf;
use Olisa\Db;
use Olisa\Repo;
use Olisa\Util;

Auth::start();

// A "next" target is only honoured when it is a local path — an open redirect
// on a login page is a phishing tool.
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
if ($next !== '' && (!str_starts_with($next, '/') || str_starts_with($next, '//'))) {
    $next = '';
}

if (Auth::check()) {
    Util::redirect($next !== '' ? $next : 'index.php');
}

$error = '';
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::valid(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Enter your email and password.';
        } else {
            $result = Auth::login($email, $password);
            if ($result['ok']) {
                Util::redirect($next !== '' ? $next : 'index.php');
            }
            $error = (string) $result['error'];
        }
    }
}

// A fresh install has no accounts — say so rather than letting someone guess.
$noUsers = false;
try {
    $noUsers = Db::isInstalled() && (int) Db::scalar('SELECT COUNT(*) FROM admin_users') === 0;
} catch (\Throwable) {
    $error = $error ?: 'The database is not reachable. Check config.php.';
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in — Trial Path Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
  <main class="login-card">
    <div class="brand">
      <div class="brand-mark">TP</div>
      <div class="brand-text">
        <div class="brand-name" style="color:var(--ink)">TRIAL PATH</div>
        <div class="brand-sub">ADMIN DASHBOARD</div>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert error"><?= Util::e($error) ?></div>
    <?php endif; ?>

    <?php if ($noUsers): ?>
      <div class="alert warn">
        No admin accounts exist yet. Create the first one from the command line:
        <code class="mono">php app/bin/create-admin.php</code>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <?= Csrf::field() ?>
      <input type="hidden" name="next" value="<?= Util::e($next) ?>">

      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= Util::e($email) ?>"
               required autocomplete="username" autofocus>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>

      <button type="submit" class="btn">Sign in</button>
    </form>

    <p class="xsmall muted" style="margin-top:18px;margin-bottom:0">
      This dashboard holds health screening information. Do not share your
      password, and sign out when you are finished.
    </p>
  </main>
</body>
</html>
