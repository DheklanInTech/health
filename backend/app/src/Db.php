<?php

declare(strict_types=1);

namespace Olisa;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. Every query in this codebase goes through here with bound
 * parameters — there is no string interpolation of user input anywhere.
 *
 * Supports MySQL (production, cPanel) and SQLite (local testing). The SQL used
 * by Repo is deliberately restricted to the subset both engines share.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = Config::str('db.driver', 'mysql');

        try {
            if ($driver === 'sqlite') {
                $path = Config::str('db.sqlite_path');
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec('PRAGMA foreign_keys = ON');
                // Lets readers continue while a write is in flight.
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA busy_timeout = 5000');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    Config::str('db.host', 'localhost'),
                    Config::int('db.port', 3306),
                    Config::str('db.name'),
                    Config::str('db.charset', 'utf8mb4')
                );
                $pdo = new PDO($dsn, Config::str('db.user'), Config::str('db.password'), [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements, not client-side interpolation.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            }
        } catch (PDOException $e) {
            // The message can contain credentials — log it, show nothing.
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database unavailable', 0, $e);
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function isSqlite(): bool
    {
        return Config::str('db.driver', 'mysql') === 'sqlite';
    }

    /** @param array<string|int, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::conn();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** True when the expected tables exist — used to give a clear setup error. */
    public static function isInstalled(): bool
    {
        try {
            self::scalar('SELECT 1 FROM submissions LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Runs the schema file for the active driver. Safe to re-run. */
    public static function migrate(): void
    {
        $file = APP_ROOT . '/schema/' . (self::isSqlite() ? 'sqlite.sql' : 'mysql.sql');
        if (!is_file($file)) {
            throw new RuntimeException("Schema file not found: {$file}");
        }
        $sql = (string) file_get_contents($file);

        // Strip "--" line comments first. Splitting on ";" alone would let a
        // comment block preceding a statement swallow that statement.
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }

        $pdo = self::conn();
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }
}
