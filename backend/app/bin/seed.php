<?php
/**
 * Fills the database with realistic mock applications so the dashboard can be
 * exercised before the site is live.
 *
 *   php app/bin/seed.php              # 120 applications over the last 45 days
 *   php app/bin/seed.php --count=400 --days=90
 *   php app/bin/seed.php --fresh      # wipe existing submissions first
 *
 * Answers are generated so that each trial produces a believable mix of
 * eligible, review and excluded verdicts — the point is to see the triage
 * surfaces do real work, not to see 120 identical green rows.
 *
 * CLI only. It refuses to run against a database that already holds real
 * traffic unless --force is given.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Olisa\Db;
use Olisa\Eligibility;
use Olisa\Repo;
use Olisa\Trials;
use Olisa\Util;

/** @return array<string, string> */
function options(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $out[$m[1]] = $m[2] ?? '1';
        }
    }
    return $out;
}

$opts  = options($argv);
$count = max(1, (int) ($opts['count'] ?? 120));
$days  = max(1, (int) ($opts['days'] ?? 45));

if (!Db::isInstalled()) {
    fwrite(STDERR, "Tables not found. Run: php app/bin/migrate.php\n");
    exit(1);
}

$existing = (int) Db::scalar('SELECT COUNT(*) FROM submissions');

if (isset($opts['fresh'])) {
    Db::run('DELETE FROM submission_notes');
    Db::run('DELETE FROM submissions');
    Db::run("DELETE FROM audit_events WHERE target_type = 'submission'");
    echo "Cleared {$existing} existing applications.\n";
    $existing = 0;
}

if ($existing > 0 && !isset($opts['force'])) {
    fwrite(STDERR, "The database already holds {$existing} applications.\n");
    fwrite(STDERR, "Use --fresh to replace them, or --force to add on top.\n");
    exit(1);
}

// ------------------------------------------------------------- name material

$firstNames = ['Amara', 'Chidi', 'Ngozi', 'Emeka', 'Folake', 'Tunde', 'Ifeoma', 'Bola',
    'Sarah', 'Michael', 'Grace', 'David', 'Linda', 'James', 'Patricia', 'Robert',
    'Aisha', 'Yusuf', 'Blessing', 'Samuel', 'Margaret', 'Daniel', 'Ruth', 'Peter',
    'Helen', 'Joseph', 'Nkechi', 'Olumide', 'Rebecca', 'Anthony'];

$lastNames = ['Okafor', 'Adeyemi', 'Okonkwo', 'Balogun', 'Eze', 'Nwosu', 'Abubakar',
    'Johnson', 'Williams', 'Brown', 'Taylor', 'Anderson', 'Thomas', 'Jackson',
    'Ibrahim', 'Chukwu', 'Adebayo', 'Obi', 'Lawson', 'Martins', 'Smith', 'Clark'];

$domains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com'];

/** Weighted pick: keys are values, values are relative weights. */
function weighted(array $choices): string
{
    $total = array_sum($choices);
    $roll  = random_int(1, max(1, $total));
    foreach ($choices as $value => $weight) {
        $roll -= $weight;
        if ($roll <= 0) {
            return (string) $value;
        }
    }
    return (string) array_key_first($choices);
}

function yesNo(int $percentYes): string
{
    return random_int(1, 100) <= $percentYes ? 'Yes' : 'No';
}

/**
 * Builds answers for one trial. The yes-rates are set so roughly 45–60% pass
 * screening, 20–30% need review and the rest are excluded.
 */
function makeAnswers(string $trial, string $email, string $phone): array
{
    $sex = weighted(['Female' => 52, 'Male' => 45, 'Not Specified' => 3]);

    $base = [
        'email' => $email,
        'phone' => $phone,
        'sex'   => $sex,
    ];

    switch ($trial) {
        case 'alzheimers':
            // Skews old by design — this trial has a 65+ floor.
            $age = random_int(1, 100) <= 78 ? random_int(65, 88) : random_int(52, 64);
            return $base + [
                'age'      => (string) $age,
                'weight'   => (string) random_int(120, 215),
                'height'   => (string) round(random_int(51, 63) / 10, 1),
                'over65'   => $age >= 65 ? 'Yes' : 'No',
                'dementia' => yesNo(34),
            ];

        case 'hsv':
            return $base + [
                'age'              => (string) random_int(19, 58),
                'weight'           => (string) random_int(115, 240),
                'height'           => (string) round(random_int(51, 64) / 10, 1),
                'hsvstatus'        => weighted([
                    'No / Unknown' => 30, 'HSV-1 Only' => 33,
                    'HSV-2 Only' => 26, 'Both HSV-1 and HSV-2' => 11,
                ]),
                'outbreaks'        => yesNo(41),
                'antiviral'        => yesNo(24),
                'immunosuppressed' => yesNo(8),
                'priortrial'       => yesNo(7),
                'pregnant'         => $sex === 'Female' ? yesNo(9) : 'No',
            ];

        case 'flu':
            return $base + [
                'age'              => (string) random_int(19, 79),
                'weight'           => (string) random_int(118, 250),
                'height'           => (string) round(random_int(51, 64) / 10, 1),
                'asthma'           => yesNo(29),
                'exacerbation'     => yesNo(13),
                'copd'             => yesNo(11),
                'oxygentherapy'    => yesNo(5),
                'smoking'          => weighted(['Never Smoker' => 58, 'Former Smoker' => 27, 'Current Smoker' => 15]),
                'cardiovascular'   => yesNo(17),
                'infarction'       => yesNo(7),
                'heartfailure'     => yesNo(6),
                'priorvaccine'     => yesNo(14),
                'anaphylaxis'      => yesNo(4),
                'guillainbarre'    => yesNo(2),
                'immunosuppressed' => yesNo(6),
            ];

        default: // weight-loss
            // Weighted heavy so most applicants clear the BMI 27 floor.
            return $base + [
                'age'           => (string) random_int(19, 67),
                'weight'        => (string) random_int(170, 285),
                'height'        => (string) round(random_int(51, 63) / 10, 1),
                'pancreatitis'  => yesNo(6),
                'gallbladder'   => yesNo(14),
                'hypertensive'  => yesNo(31),
                'diabetic'      => yesNo(24),
                'mentalillness' => yesNo(16),
                'mdd'           => yesNo(12),
                'bipolar'       => yesNo(5),
                'schizophrenic' => yesNo(2),
                'anxiety'       => yesNo(21),
                'adhd'          => yesNo(9),
            ];
    }
}

/** Older applications are more likely to have been worked through already. */
function pickStatus(int $ageDays, string $verdict): string
{
    if ($ageDays <= 2) {
        return weighted(['new' => 80, 'reviewing' => 20]);
    }
    if ($ageDays <= 7) {
        return weighted(['new' => 25, 'reviewing' => 40, 'contacted' => 20, 'eligible' => 15]);
    }
    if ($verdict === 'excluded') {
        return weighted(['ineligible' => 68, 'reviewing' => 17, 'withdrawn' => 15]);
    }
    return weighted([
        'enrolled' => 26, 'contacted' => 22, 'eligible' => 20,
        'reviewing' => 14, 'ineligible' => 10, 'withdrawn' => 8,
    ]);
}

$noteTemplates = [
    'Called — no answer. Left a voicemail asking them to call back.',
    'Spoke with the applicant. Happy to proceed, prefers morning appointments.',
    'Screening call completed. Answers consistent with the form.',
    'Sent the information sheet and consent form by email.',
    'Applicant asked to be contacted after the end of the month.',
    'Confirmed contact details. Phone number on the form had a typo.',
    'Referred to the site coordinator for a physical screening.',
    'Applicant withdrew — travel commitments over the study window.',
];

$slugs = Trials::slugs();
// Weight-loss is the site's landing page, so it gets most of the traffic.
$trialWeights = ['weight-loss' => 42, 'flu' => 26, 'alzheimers' => 18, 'hsv' => 14];

echo "Seeding {$count} applications across {$days} days...\n";

$created = 0;
$verdictTally = ['eligible' => 0, 'review' => 0, 'excluded' => 0];

for ($i = 0; $i < $count; $i++) {
    $trial = weighted(array_intersect_key($trialWeights, array_flip($slugs)));

    $first = $firstNames[array_rand($firstNames)];
    $last  = $lastNames[array_rand($lastNames)];
    $email = strtolower($first . '.' . $last . random_int(1, 499) . '@' . $domains[array_rand($domains)]);
    $phone = '+234 ' . random_int(700, 909) . ' ' . random_int(100, 999) . ' ' . random_int(1000, 9999);

    $answers     = makeAnswers($trial, $email, $phone);
    $eligibility = Eligibility::evaluate($trial, $answers);
    $verdictTally[$eligibility['verdict']]++;

    // Spread across the window, with fewer at the weekend.
    $ageDays = random_int(0, $days - 1);
    $stamp   = time() - ($ageDays * 86400) - random_int(0, 86399);
    if (in_array((int) gmdate('N', $stamp), [6, 7], true) && random_int(1, 100) <= 55) {
        $stamp -= 2 * 86400;
    }
    $createdAt = gmdate('Y-m-d H:i:s', $stamp);

    $status = pickStatus($ageDays, $eligibility['verdict']);
    $result = Repo::createSubmission(
        $trial,
        $answers,
        $eligibility,
        hash_hmac('sha256', '203.0.113.' . random_int(1, 254), 'seed'),
        'Mozilla/5.0 (seeded mock data)'
    );

    // Backdate — createSubmission always stamps "now".
    $updatedAt = $status === 'new' ? $createdAt : gmdate('Y-m-d H:i:s', $stamp + random_int(3600, 172800));
    Db::run('UPDATE submissions SET status = ?, created_at = ?, updated_at = ? WHERE id = ?',
        [$status, $createdAt, $updatedAt, $result['id']]);

    // Anything past "new" plausibly has a note or two against it.
    if ($status !== 'new' && random_int(1, 100) <= 55) {
        foreach (range(1, random_int(1, 2)) as $n) {
            Db::run(
                'INSERT INTO submission_notes (id, submission_id, author_email, body, created_at) VALUES (?,?,?,?,?)',
                [
                    Util::uuid(),
                    $result['id'],
                    'coordinator@trialpath.org',
                    $noteTemplates[array_rand($noteTemplates)],
                    gmdate('Y-m-d H:i:s', $stamp + ($n * random_int(7200, 86400))),
                ]
            );
        }
    }

    $created++;
    if ($created % 25 === 0) {
        echo "  {$created}/{$count}\n";
    }
}

// A little audit history so that page is not empty either.
Repo::audit('coordinator@trialpath.org', 'login.success', 'session');
Repo::audit('coordinator@trialpath.org', 'submissions.exported', 'submission', null,
    ['rows' => $created, 'filters' => ['status' => 'new']]);

echo "\nDone — {$created} applications created.\n\n";
echo "Screening verdicts:\n";
foreach ($verdictTally as $verdict => $n) {
    printf("  %-9s %3d  (%d%%)\n", $verdict, $n, $created > 0 ? round(($n / $created) * 100) : 0);
}

echo "\nBy trial:\n";
foreach (Db::all('SELECT trial, COUNT(*) AS n FROM submissions GROUP BY trial ORDER BY n DESC') as $row) {
    printf("  %-28s %3d\n", Trials::name((string) $row['trial']), (int) $row['n']);
}

echo "\nBy status:\n";
foreach (Db::all('SELECT status, COUNT(*) AS n FROM submissions GROUP BY status ORDER BY n DESC') as $row) {
    printf("  %-12s %3d\n", (string) $row['status'], (int) $row['n']);
}
