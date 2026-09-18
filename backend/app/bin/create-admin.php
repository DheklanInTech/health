<?php
/**
 * Creates an admin account.
 *
 *   php app/bin/create-admin.php
 *   php app/bin/create-admin.php --email=you@example.org --name="Your Name" --role=owner
 *
 * With no --password the script generates one and prints it once.
 * CLI only — this file is not reachable over HTTP.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Olisa\Db;
use Olisa\Repo;

const MIN_PASSWORD = 12;

/** @return array<string, string> */
function options(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        }
    }
    return $out;
}

function ask(string $prompt, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $prompt . $suffix . ': ';
    $line = trim((string) fgets(STDIN));
    return $line === '' ? $default : $line;
}

if (!Db::isInstalled()) {
    fwrite(STDERR, "The database tables do not exist yet. Run: php app/bin/migrate.php\n");
    exit(1);
}

$opts = options($argv);

$email = $opts['email'] ?? ask('Email');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That is not a valid email address.\n");
    exit(1);
}

if (Repo::userByEmail($email) !== null) {
    fwrite(STDERR, "An account with that email already exists.\n");
    exit(1);
}

$name = $opts['name'] ?? ask('Full name');
if ($name === '') {
    fwrite(STDERR, "A name is required.\n");
    exit(1);
}

// The first account has to be an owner, or nobody can manage the team.
$isFirst = (int) Db::scalar('SELECT COUNT(*) FROM admin_users') === 0;
$role = $opts['role'] ?? ($isFirst ? 'owner' : ask('Role (owner/admin/viewer)', 'admin'));
if (!in_array($role, ['owner', 'admin', 'viewer'], true)) {
    fwrite(STDERR, "Role must be owner, admin or viewer.\n");
    exit(1);
}
if ($isFirst && $role !== 'owner') {
    echo "The first account must be an owner — using owner.\n";
    $role = 'owner';
}

$generated = false;
$password  = $opts['password'] ?? '';
if ($password === '') {
    $password  = bin2hex(random_bytes(9));
    $generated = true;
} elseif (strlen($password) < MIN_PASSWORD) {
    fwrite(STDERR, "The password must be at least " . MIN_PASSWORD . " characters.\n");
    exit(1);
}

// A password supplied on the command line ends up in the shell history, so
// force a change at first sign-in either way.
$id = Repo::createUser($email, $name, $role, password_hash($password, PASSWORD_DEFAULT), true);
Repo::audit('cli', 'user.created', 'user', $id, ['email' => $email, 'role' => $role]);

echo "\nCreated {$role} account for {$email}\n";
if ($generated) {
    echo "Password: {$password}\n";
}
echo "\nThis password must be changed at first sign-in. Sign in at /admin/login.php\n";
