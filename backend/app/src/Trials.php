<?php

declare(strict_types=1);

namespace Olisa;

use RuntimeException;

/**
 * Reads shared/trials.json — the same file the React screening form is built
 * from. Server-side validation, the dashboard's answer labels and the CSV
 * export all derive from it, so a question can never exist on the form but be
 * unknown to the backend.
 */
final class Trials
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $trials = null;

    private static function file(): string
    {
        $candidates = [
            APP_ROOT . '/shared/trials.json',        // deployed layout
            dirname(APP_ROOT) . '/shared/trials.json',
            dirname(APP_ROOT, 2) . '/shared/trials.json', // repo layout
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException('shared/trials.json not found. Copy it next to the app directory when deploying.');
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$trials !== null) {
            return self::$trials;
        }
        $raw = file_get_contents(self::file());
        if ($raw === false) {
            throw new RuntimeException('Unable to read shared/trials.json');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['trials']) || !is_array($data['trials'])) {
            throw new RuntimeException('shared/trials.json is malformed');
        }
        self::$trials = $data['trials'];
        return self::$trials;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    /** @return array<string, mixed> */
    public static function get(string $slug): array
    {
        $trials = self::all();
        if (!isset($trials[$slug])) {
            throw new RuntimeException("Unknown trial: {$slug}");
        }
        return $trials[$slug];
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function name(string $slug): string
    {
        return self::exists($slug) ? (string) self::get($slug)['name'] : $slug;
    }

    public static function idPrefix(string $slug): string
    {
        return self::exists($slug) ? (string) self::get($slug)['idPrefix'] : 'XX';
    }

    public static function path(string $slug): string
    {
        return self::exists($slug) ? (string) self::get($slug)['path'] : '/';
    }

    /**
     * Every field of a trial, flattened in the order it is asked.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(string $slug): array
    {
        $fields = [];
        foreach (self::get($slug)['steps'] as $step) {
            foreach ($step['fields'] as $field) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Field name => question label, for rendering answers in the dashboard.
     *
     * @return array<string, string>
     */
    public static function labels(string $slug): array
    {
        $labels = [];
        foreach (self::fields($slug) as $field) {
            $labels[(string) $field['name']] = (string) $field['label'];
        }
        return $labels;
    }

    /**
     * Steps with their labels, for grouping answers on the detail page.
     *
     * @return array<int, array{label: string, fields: array<int, array<string, mixed>>}>
     */
    public static function steps(string $slug): array
    {
        /** @var array<int, array{label: string, fields: array<int, array<string, mixed>>}> */
        return self::get($slug)['steps'];
    }

    /** Names of every field across every trial — the union used by CSV exports. */
    public static function allFieldNames(): array
    {
        $names = [];
        foreach (self::slugs() as $slug) {
            foreach (self::fields($slug) as $field) {
                $names[(string) $field['name']] = true;
            }
        }
        return array_keys($names);
    }
}
