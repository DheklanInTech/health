<?php

declare(strict_types=1);

namespace Olisa;

/**
 * CSV export, streamed row by row so a large export does not exhaust the
 * memory limit typical of shared hosting.
 */
final class Csv
{
    /**
     * Neutralises spreadsheet formula injection: a value starting with = + - @
     * is executed by Excel and Sheets when the file is opened.
     */
    private static function safe(mixed $value): string
    {
        $string = (string) $value;
        if ($string !== '' && str_contains("=+-@\t\r", $string[0])) {
            return "'" . $string;
        }
        return $string;
    }

    /**
     * @param array<string, string> $filters
     */
    public static function streamSubmissions(array $filters, string $filename): never
    {
        // Union of every trial's fields, so one file can hold mixed trials.
        $answerKeys = Trials::allFieldNames();

        // A PHP notice printed mid-stream would corrupt the download, so
        // errors go to the log only for this response regardless of app.debug.
        ini_set('display_errors', '0');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        // Flush any buffering so the download starts immediately.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        // UTF-8 BOM so Excel opens accented names correctly.
        fwrite($out, "\xEF\xBB\xBF");

        $header = array_merge(
            ['Application ID', 'Trial', 'Status', 'Screening verdict', 'Screening flags', 'BMI',
             'Submitted (UTC)', 'Last updated (UTC)'],
            array_map(static fn(string $key) => self::headerLabel($key), $answerKeys)
        );
        fputcsv($out, $header, ',', '"', '');

        $stmt = Repo::streamSubmissions($filters);
        while (($row = $stmt->fetch()) !== false) {
            $answers     = json_decode((string) $row['answers'], true) ?: [];
            $eligibility = json_decode((string) $row['eligibility'], true) ?: [];
            $flags       = implode('; ', array_column($eligibility['flags'] ?? [], 'label'));

            $line = [
                self::safe($row['application_id']),
                self::safe(Trials::name((string) $row['trial'])),
                self::safe(Repo::STATUS_LABELS[$row['status']] ?? $row['status']),
                self::safe(Eligibility::verdictLabel((string) $row['verdict'])),
                self::safe($flags),
                self::safe($row['bmi'] ?? ''),
                self::safe($row['created_at']),
                self::safe($row['updated_at']),
            ];
            foreach ($answerKeys as $key) {
                $line[] = self::safe($answers[$key] ?? '');
            }
            fputcsv($out, $line, ',', '"', '');
        }

        fclose($out);
        exit;
    }

    /** Uses the question text as the column heading where one exists. */
    private static function headerLabel(string $key): string
    {
        foreach (Trials::slugs() as $slug) {
            $labels = Trials::labels($slug);
            if (isset($labels[$key])) {
                return $labels[$key];
            }
        }
        return $key;
    }
}
