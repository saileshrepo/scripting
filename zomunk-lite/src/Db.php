<?php

namespace Zomunk;

use PDO;
use RuntimeException;

/** Thin PDO wrapper: one shared connection, schema bootstrap, small helpers. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $config = zomunk_config()['db'];
        $dsn = $config['dsn'];

        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, strlen('sqlite:'));
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create database directory: $dir");
            }
        }

        $pdo = new PDO($dsn, $config['user'] ?: null, $config['password'] ?: null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$pdo = $pdo;
    }

    public static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Applies schema.sql (or schema.mysql.sql). Safe to run repeatedly. */
    public static function migrate(): void
    {
        $root = zomunk_config()['root'];
        $file = self::driver() === 'mysql' ? $root . '/schema.mysql.sql' : $root . '/schema.sql';
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Cannot read schema file: $file");
        }

        // Drop "--" comment lines first, then split on ";". Neither schema file
        // contains a ";" inside a literal, so this stays a safe split.
        $lines = array_filter(
            explode("\n", $sql),
            static fn(string $line) => !str_starts_with(ltrim($line), '--'),
        );

        foreach (explode(';', implode("\n", $lines)) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            self::pdo()->exec($statement);
        }
    }

    public static function query(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::query($sql, $params);
        return $rows[0] ?? null;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public static function insert(string $sql, array $params = []): string
    {
        self::execute($sql, $params);
        return self::pdo()->lastInsertId();
    }

    /** For tests: swap in an in-memory database. */
    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
