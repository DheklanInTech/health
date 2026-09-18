<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Presentation helpers: the page chrome, badges and the hand-built SVG charts.
 *
 * Charts are drawn here rather than pulled from a library so the dashboard
 * deploys as plain files — no build step, no CDN dependency, nothing to break
 * when a script host goes down.
 */
final class View
{
    /** Fixed categorical order. Never cycled — a fifth trial gets its own step. */
    public const SERIES = ['#2f9d6c', '#2f7cc4', '#b5327f', '#d97706'];

    public static function trialColor(string $slug): string
    {
        $index = array_search($slug, Trials::slugs(), true);
        return self::SERIES[is_int($index) ? $index % count(self::SERIES) : 0];
    }

    public static function statusBadge(string $status): string
    {
        $tone = match ($status) {
            'enrolled', 'eligible'  => 'good',
            'ineligible', 'withdrawn' => 'serious',
            'contacted', 'reviewing' => 'info',
            'new'                    => 'accent',
            default                  => '',
        };
        $label = Repo::STATUS_LABELS[$status] ?? $status;
        return '<span class="badge ' . $tone . '">' . Util::e($label) . '</span>';
    }

    public static function verdictBadge(string $verdict): string
    {
        $tone = match ($verdict) {
            'eligible' => 'good',
            'excluded' => 'serious',
            default    => 'warn',
        };
        return '<span class="badge ' . $tone . '">' . Util::e(Eligibility::verdictLabel($verdict)) . '</span>';
    }

    public static function trialLabel(string $slug): string
    {
        return '<span class="nowrap"><span class="trial-dot" style="background:'
            . Util::e(self::trialColor($slug)) . '"></span>' . Util::e(Trials::name($slug)) . '</span>';
    }

    // ------------------------------------------------------------------ chrome

    /** @param array<string, mixed> $options */
    public static function header(string $title, array $options = []): void
    {
        $user    = Auth::user() ?? ['name' => '', 'email' => '', 'role' => ''];
        $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $subtitle = (string) ($options['subtitle'] ?? '');
        $actions  = (string) ($options['actions'] ?? '');

        // A count of unreviewed applications, shown against the queue link.
        $pending = 0;
        try {
            $pending = (int) Db::scalar("SELECT COUNT(*) FROM submissions WHERE status = 'new'");
        } catch (\Throwable) {
            // A broken database should still render the shell and its error.
        }

        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store, private');
        // No inline script anywhere in the dashboard, so this can stay strict.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com; script-src 'self'; frame-ancestors 'none'");

        $nav = [
            ['index.php',       'Overview',    self::icon('grid'),  null],
            ['submissions.php', 'Applications', self::icon('list'),  $pending],
        ];
        // Messaging is an edit capability — viewers can read the queue's
        // effects in the activity log but cannot write to applicants.
        if (Auth::can('edit')) {
            $nav[] = ['messages.php', 'Messages', self::icon('mail'), null];
        }
        $nav[] = ['audit.php', 'Activity log', self::icon('clock'), null];
        if (Auth::can('users')) {
            $nav[] = ['users.php', 'Team', self::icon('users'), null];
        }
        $nav[] = ['password.php', 'My password', self::icon('key'), null];

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="noindex, nofollow">';
        echo '<title>' . Util::e($title) . ' — Trial Path Admin</title>';
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;600;800&display=swap" rel="stylesheet">';
        echo '<link rel="stylesheet" href="assets/admin.css">';
        echo '</head><body><div class="shell">';

        echo '<aside class="sidebar"><div class="brand"><div class="brand-mark">TP</div>';
        echo '<div class="brand-text"><div class="brand-name">TRIAL PATH</div>';
        echo '<div class="brand-sub">ADMIN</div></div></div>';

        echo '<nav class="nav">';
        // Detail pages highlight the section they belong to, not nothing.
        $section = match ($current) {
            'message.php', 'message-preview.php', 'suppressions.php' => 'messages.php',
            'submission.php', 'export.php' => 'submissions.php',
            default => $current,
        };

        foreach ($nav as [$href, $label, $icon, $count]) {
            $isCurrent = $section === $href;
            echo '<a href="' . Util::e($href) . '"' . ($isCurrent ? ' aria-current="page"' : '') . '>'
                . $icon . '<span>' . Util::e($label) . '</span>'
                . ($count ? '<span class="count">' . (int) $count . '</span>' : '')
                . '</a>';
        }
        echo '</nav>';

        echo '<div class="sidebar-foot"><strong>' . Util::e((string) $user['name']) . '</strong>'
            . Util::e(ucfirst((string) $user['role'])) . ' · <a href="logout.php">Sign out</a></div>';
        echo '</aside>';

        echo '<div class="main"><div class="topbar"><div><h1>' . Util::e($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<div class="sub">' . $subtitle . '</div>';
        }
        echo '</div>' . ($actions !== '' ? '<div class="row">' . $actions . '</div>' : '') . '</div>';
        echo '<div class="content">';
    }

    public static function footer(): void
    {
        echo '</div></div></div></body></html>';
    }

    public static function flash(): void
    {
        Auth::start();
        if (empty($_SESSION['flash'])) {
            return;
        }
        foreach ((array) $_SESSION['flash'] as $item) {
            echo '<div class="alert ' . Util::e($item['type']) . '">' . Util::e($item['message']) . '</div>';
        }
        unset($_SESSION['flash']);
    }

    public static function setFlash(string $type, string $message): void
    {
        Auth::start();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    private static function icon(string $name): string
    {
        $paths = [
            'grid'  => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
            'list'  => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
            'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
            'key'   => '<path d="M21 2l-2 2m-7.6 7.6a5 5 0 1 1-7.07 7.07 5 5 0 0 1 7.07-7.07zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3"/>',
            'mail'  => '<rect x="2" y="4" width="20" height="16"/><polyline points="2 6 12 13 22 6"/>',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="square" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }

    // ------------------------------------------------------------------ charts

    /**
     * Submissions per day, stacked by trial.
     *
     * Bars rather than an area: the values are daily counts, often small
     * integers, and a stacked area would imply a continuity the data does not
     * have. Hover is a native <title> tooltip — no JavaScript in the dashboard.
     *
     * @param array<int, array<string, mixed>> $daily rows of {day, trial, n}
     */
    public static function timeSeries(array $daily, int $days = 30): string
    {
        // Build a dense day axis so gaps read as zero, not as missing time.
        $axis = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $axis[gmdate('Y-m-d', time() - ($i * 86400))] = [];
        }
        foreach ($daily as $row) {
            $day = (string) $row['day'];
            if (isset($axis[$day])) {
                $axis[$day][(string) $row['trial']] = (int) $row['n'];
            }
        }

        $slugs = Trials::slugs();
        $totals = array_map(static fn(array $d): int => array_sum($d), $axis);
        $max = max(1, max($totals ?: [1]));
        // Round the ceiling up so gridlines land on whole numbers.
        $step = max(1, (int) ceil($max / 4));
        $ceiling = $step * 4;

        $width = 720;
        $height = 220;
        $padLeft = 30;
        $padBottom = 26;
        $padTop = 8;
        $plotW = $width - $padLeft;
        $plotH = $height - $padBottom - $padTop;
        $count = max(1, count($axis));
        $slot = $plotW / $count;
        $barW = max(3.0, min(18.0, $slot - 3));

        $svg = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" '
             . 'aria-label="Applications per day for the last ' . $days . ' days, by trial" '
             . 'preserveAspectRatio="none">';

        // Recessive gridlines and a value axis.
        for ($i = 0; $i <= 4; $i++) {
            $y = $padTop + $plotH - ($plotH * $i / 4);
            $svg .= '<line class="grid-line" x1="' . $padLeft . '" y1="' . round($y, 1)
                  . '" x2="' . $width . '" y2="' . round($y, 1) . '"/>';
            $svg .= '<text class="axis-text" x="' . ($padLeft - 6) . '" y="' . round($y + 3.5, 1)
                  . '" text-anchor="end">' . ($step * $i) . '</text>';
        }

        $index = 0;
        foreach ($axis as $day => $counts) {
            $x = $padLeft + ($index * $slot) + (($slot - $barW) / 2);
            $stackY = $padTop + $plotH;
            $total = array_sum($counts);

            foreach ($slugs as $s => $slug) {
                $n = (int) ($counts[$slug] ?? 0);
                if ($n === 0) {
                    continue;
                }
                $h = ($n / $ceiling) * $plotH;
                $stackY -= $h;
                $svg .= '<rect class="seg" x="' . round($x, 1) . '" y="' . round($stackY, 1)
                      . '" width="' . round($barW, 1) . '" height="' . round($h, 1)
                      . '" fill="' . self::SERIES[$s % count(self::SERIES)] . '">'
                      . '<title>' . Util::e(gmdate('j M', strtotime($day . ' UTC') ?: time()))
                      . ' — ' . Util::e(Trials::name($slug)) . ': ' . $n . '</title></rect>';
            }

            // Whole-column hover target, so an empty day still reports zero.
            $svg .= '<rect class="hit" x="' . round($padLeft + ($index * $slot), 1) . '" y="' . $padTop
                  . '" width="' . round($slot, 1) . '" height="' . $plotH . '">'
                  . '<title>' . Util::e(gmdate('j M Y', strtotime($day . ' UTC') ?: time()))
                  . ' — ' . $total . ' application' . ($total === 1 ? '' : 's') . '</title></rect>';

            // Label roughly weekly. The final tick is only drawn when it is far
            // enough from the previous one, otherwise the two labels overlap.
            $isWeekly = $index % 7 === 0;
            $isLast   = $index === $count - 1;
            if ($isWeekly || ($isLast && ($count - 1) % 7 >= 3)) {
                $svg .= '<text class="axis-text" x="' . round($padLeft + ($index * $slot) + ($slot / 2), 1)
                      . '" y="' . ($height - 8) . '" text-anchor="middle">'
                      . Util::e(gmdate('j M', strtotime($day . ' UTC') ?: time())) . '</text>';
            }
            $index++;
        }

        $svg .= '</svg>';
        return $svg;
    }

    /** Legend for the trial series — identity is never carried by colour alone. */
    public static function trialLegend(array $byTrial = []): string
    {
        $html = '<div class="legend">';
        foreach (Trials::slugs() as $i => $slug) {
            $count = isset($byTrial[$slug]) ? ' (' . (int) $byTrial[$slug] . ')' : '';
            $html .= '<span class="item"><span class="swatch" style="background:'
                   . self::SERIES[$i % count(self::SERIES)] . '"></span>'
                   . Util::e(Trials::name($slug)) . Util::e($count) . '</span>';
        }
        return $html . '</div>';
    }

    /**
     * Horizontal bars for a distribution. One series, so no legend — each row
     * is directly labelled, which is what makes the chart readable.
     *
     * @param array<string, int> $data label => count
     */
    public static function barList(array $data, ?string $color = null): string
    {
        $max = max(1, max($data ?: [1]));
        $html = '';
        foreach ($data as $label => $value) {
            $pct = ($value / $max) * 100;
            $html .= '<div class="bar-row"><span class="k">' . Util::e($label) . '</span>'
                   . '<span class="bar-track"><span class="bar-fill" style="width:' . round($pct, 1) . '%'
                   . ($color ? ';background:' . Util::e($color) : '') . '"></span></span>'
                   . '<span class="n tabular">' . (int) $value . '</span></div>';
        }
        return $html;
    }

    /**
     * Single 100%-stacked bar — the screening verdict split.
     *
     * @param array<int, array{label: string, value: int, color: string}> $parts
     */
    public static function splitBar(array $parts): string
    {
        $total = array_sum(array_column($parts, 'value'));
        if ($total === 0) {
            return '<p class="muted small">No applications yet.</p>';
        }

        $html = '<div class="split">';
        foreach ($parts as $part) {
            if ($part['value'] === 0) {
                continue;
            }
            $pct = ($part['value'] / $total) * 100;
            $html .= '<span style="width:' . round($pct, 2) . '%;background:' . Util::e($part['color']) . '"'
                   . ' title="' . Util::e($part['label'] . ': ' . $part['value']) . '"></span>';
        }
        $html .= '</div><div class="legend">';
        foreach ($parts as $part) {
            $pct = $total > 0 ? round(($part['value'] / $total) * 100) : 0;
            $html .= '<span class="item"><span class="swatch" style="background:' . Util::e($part['color'])
                   . '"></span>' . Util::e($part['label']) . ' — <strong>' . (int) $part['value']
                   . '</strong> (' . $pct . '%)</span>';
        }
        return $html . '</div>';
    }
}
