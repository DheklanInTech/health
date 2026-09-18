<?php
/**
 * Renders an email exactly as it will be sent, for the preview iframe.
 *
 * Its own document, not a div inside the dashboard: the template carries a full
 * <html> with its own resets and media queries, and dropping that into the page
 * would let the dashboard's stylesheet reach into it — which is precisely the
 * thing a preview must not do.
 *
 * With no id it renders the session draft; with one it renders a message that
 * has already been sent, so the archive shows the real thing rather than a
 * reconstruction.
 */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\EmailTemplate;
use Olisa\Outbox;
use Olisa\Repo;

$user = Auth::requireLogin();
Auth::requireCan('edit');
Auth::start();

$id = trim((string) ($_GET['id'] ?? ''));

if ($id !== '') {
    $message = Repo::getMessage($id);
    if ($message === null) {
        http_response_code(404);
        exit('Not found');
    }
    $recipients = Repo::messageRecipients($id, 'all', 1);
    $recipient  = Outbox::previewRecipient($recipients, (string) $user['email']);
} else {
    $draft = $_SESSION['email_draft'] ?? [];
    if (!is_array($draft) || trim((string) ($draft['body'] ?? '')) === '') {
        $draft = [
            'subject' => 'Nothing drafted yet',
            'body'    => "Write your message on the left, then save the draft.\n\nIt will appear here exactly as it will arrive.",
        ];
    }
    $message    = $draft;
    $resolved   = Outbox::resolveAudience((string) ($draft['audience'] ?? 'single'), $draft);
    $recipient  = Outbox::previewRecipient($resolved['recipients'], (string) $user['email']);
}

$rendered = EmailTemplate::render($message, $recipient);

// Framed by messages.php on the same origin, so the dashboard's blanket DENY
// has to be relaxed here — to SAMEORIGIN, never wider.
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
// The email is all inline styles. Nothing else is allowed to load: no scripts,
// no remote images, no fonts — a preview must not be able to phone anywhere.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; "
    . "img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

echo $rendered['html'];
