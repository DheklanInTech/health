<?php
/**
 * Public endpoint for the screening forms. The only write path reachable
 * without authentication, so it is the one that needs the most care:
 *
 *   - POST only, JSON in, JSON out
 *   - same-origin enforced (no CORS headers unless explicitly configured)
 *   - honeypot field and a minimum fill time to shed bots
 *   - per-IP hourly and daily caps
 *   - every answer re-validated server-side against shared/trials.json
 *   - undeclared fields discarded, never stored
 *   - duplicate guard for double-clicks and refreshes
 */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Config;
use Olisa\Eligibility;
use Olisa\Mailer;
use Olisa\Repo;
use Olisa\Throttle;
use Olisa\Trials;
use Olisa\Util;
use Olisa\Validator;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

// ---------------------------------------------------------------- method + origin

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    Util::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * The form is served from the same origin, so a cross-origin POST is either a
 * misconfiguration or an attack. Configure security.allowed_origins only if the
 * forms are ever embedded elsewhere.
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $allowed = (array) Config::get('security.allowed_origins', []);
    $host    = $_SERVER['HTTP_HOST'] ?? '';
    $sameOrigin = $host !== '' && (
        $origin === 'https://' . $host || $origin === 'http://' . $host
    );
    if (!$sameOrigin && !in_array($origin, $allowed, true)) {
        Util::json(['ok' => false, 'error' => 'Request blocked'], 403);
    }
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

// ------------------------------------------------------------------- read body

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 64 * 1024) {
    Util::json(['ok' => false, 'error' => 'Invalid request'], 400);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    Util::json(['ok' => false, 'error' => 'Invalid request'], 400);
}

$trial = is_string($payload['trial'] ?? null) ? $payload['trial'] : '';
if (!Trials::exists($trial)) {
    Util::json(['ok' => false, 'error' => 'Unknown trial'], 400);
}

$answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

// --------------------------------------------------------------- bot screening

// Hidden field a human never sees and never fills.
if (!empty($payload['website'])) {
    // Answer as though it worked — a bot told it failed just tries again.
    Util::json(['ok' => true, 'applicationId' => Util::applicationId(Trials::idPrefix($trial))]);
}

// A genuine multi-step medical questionnaire takes longer than three seconds.
$elapsed = isset($payload['elapsedMs']) && is_numeric($payload['elapsedMs']) ? (int) $payload['elapsedMs'] : null;
if ($elapsed !== null && $elapsed < 3000) {
    Util::json(['ok' => true, 'applicationId' => Util::applicationId(Trials::idPrefix($trial))]);
}

// ------------------------------------------------------------------ rate limit

$ipHash = Util::ipHash();

$blocked = Throttle::submissionBlocked($ipHash);
if ($blocked !== null) {
    Util::json(['ok' => false, 'error' => $blocked], 429);
}

// ------------------------------------------------------------------- validate

$validator = new Validator($trial);
if (!$validator->validate($answers)) {
    Util::json(['ok' => false, 'error' => 'Please check your answers', 'fields' => $validator->errors()], 422);
}

$clean = $validator->clean();

// ------------------------------------------------------------------- persist

try {
    // A refresh or double-click within five minutes returns the original
    // reference rather than creating a second record.
    $duplicate = Repo::recentDuplicate($trial, $clean['email'] ?? '');
    if ($duplicate !== null) {
        Util::json(['ok' => true, 'applicationId' => $duplicate['application_id'], 'duplicate' => true]);
    }

    $eligibility = Eligibility::evaluate($trial, $clean);
    $created = Repo::createSubmission($trial, $clean, $eligibility, $ipHash, Util::userAgent());

    // The audit trail records that a submission arrived — never its contents.
    Repo::audit('public', 'submission.created', 'submission', $created['id'], ['trial' => $trial]);

    Mailer::notifyNewSubmission($created['application_id'], $trial);

    Util::json(['ok' => true, 'applicationId' => $created['application_id']]);
} catch (\Throwable $e) {
    // Log the detail, tell the visitor nothing that helps an attacker.
    error_log('Submission failed: ' . $e->getMessage());
    Util::json(['ok' => false, 'error' => 'We could not save your application. Please try again shortly.'], 500);
}
