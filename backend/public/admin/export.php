<?php
/**
 * CSV export of the current filter selection.
 *
 * Exports are logged: a file of health answers leaving the system is exactly
 * the event an audit trail exists for.
 */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Csv;
use Olisa\Repo;

$user = Auth::requireLogin();
Auth::requireCan('export');

$filters = [
    'q'       => trim((string) ($_GET['q'] ?? '')),
    'trial'   => (string) ($_GET['trial'] ?? 'all'),
    'status'  => (string) ($_GET['status'] ?? 'all'),
    'verdict' => (string) ($_GET['verdict'] ?? 'all'),
    'from'    => (string) ($_GET['from'] ?? ''),
    'to'      => (string) ($_GET['to'] ?? ''),
];

$count = Repo::listSubmissions($filters, 1, 10)['total'];

Repo::audit($user['email'], 'submissions.exported', 'submission', null, [
    'filters' => array_filter($filters, static fn($v) => $v !== '' && $v !== 'all'),
    'rows'    => $count,
]);

$filename = 'trial-path-applications-' . gmdate('Y-m-d-His') . '.csv';
Csv::streamSubmissions($filters, $filename);
