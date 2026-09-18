<?php
/**
 * Copy this file to config.php (same directory) and fill it in.
 *
 *   cp config.example.php config.php
 *   chmod 600 config.php
 *
 * config.php is git-ignored and lives outside the web root. It holds database
 * credentials — never move it into public_html.
 */

return [
    'app.name'  => 'Trial Path',
    'app.debug' => false,

    // Public site origin, no trailing slash. Used for links in notification email.
    'app.url' => 'https://example.org',

    // ---------------------------------------------------------------- database
    // From cPanel -> MySQL Databases. The user must have ALL PRIVILEGES on the
    // database. cPanel prefixes both names with your account, e.g. "cpuser_rh".
    'db.driver'   => 'mysql',
    'db.host'     => 'localhost',
    'db.port'     => 3306,
    'db.name'     => 'cpaneluser_olisa',
    'db.user'     => 'cpaneluser_olisa',
    'db.password' => 'CHANGE-ME',

    // For local development without MySQL, switch to:
    // 'db.driver' => 'sqlite',

    // ---------------------------------------------------------------- security
    // Random secret used to hash visitor IP addresses. Generate with:
    //   php -r "echo bin2hex(random_bytes(32));"
    // Changing it later resets rate-limit counters; nothing else depends on it.
    'security.hash_key' => 'CHANGE-ME-TO-64-RANDOM-HEX-CHARACTERS',

    // Set true only if the site sits behind Cloudflare or another proxy —
    // otherwise a visitor can forge X-Forwarded-For and dodge rate limits.
    'security.trust_proxy' => false,

    // Origins allowed to POST to /api/submit.php. Empty means same-origin only,
    // which is what you want unless the forms get embedded on another domain.
    'security.allowed_origins' => [],

    // ---------------------------------------------------------------- sessions
    // Must be false when testing over plain http, true in production.
    'session.secure'   => true,
    'session.lifetime' => 43200, // 12 hours

    // ------------------------------------------------------------------ limits
    'limits.submissions_per_hour' => 5,
    'limits.submissions_per_day'  => 15,
    'limits.login_attempts'       => 8,
    'limits.login_window'         => 900,

    // ------------------------------------------------------------------- email
    // Notifies the coordinator that an application arrived. Contains the
    // reference and a dashboard link only — never the health answers.
    'mail.notify' => false,
    'mail.to'     => 'coordinator@example.org',
    'mail.from'   => 'no-reply@example.org',

    // Shown as the sender name, and where replies go. Point mail.reply_to at a
    // mailbox someone actually reads — participants do reply to these.
    'mail.from_name' => 'Trial Path',
    'mail.reply_to'  => 'coordinator@example.org',

    // ------------------------------------------------------------------- SMTP
    // Leave mail.smtp_host empty to use PHP's mail(), which is fine for the
    // occasional notification and not fine for sending to applicants: it hands
    // the message to the local MTA, whose failures are invisible and whose
    // reputation is shared with every other account on the box.
    //
    // Create a mailbox in cPanel -> Email Accounts, then use its credentials:
    'mail.smtp_host'       => '',                    // e.g. mail.example.org
    'mail.smtp_port'       => 587,                   // 587 for tls, 465 for ssl
    'mail.smtp_user'       => '',                    // the full mailbox address
    'mail.smtp_password'   => '',
    'mail.smtp_encryption' => 'tls',                 // tls | ssl | '' (never in production)

    // Lower the batch size if the host caps messages per connection.
    'mail.batch_size'      => 25,
];
