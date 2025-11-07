<?php

declare(strict_types=1);

namespace Database;

use PDO;
use Database\QueryBuilder;
use Database\Manager;

final class DB
{
    private static ?Manager $manager = null;

    /** @var bool */
    private static $debug = false;

    public static function setup(callable $connector): void
    {
        self::$manager = new Manager($connector);
    }

    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }

    public static function debugDump(string $sql, array $params): void
    {
        if (self::$debug) {
            echo "SQL: $sql\n";
            echo "Params: " . json_encode($params, JSON_PRETTY_PRINT) . "\n";
        }
    }

    private static function m(): Manager
    {
        if (!self::$manager) {
            self::$manager = new Manager();
        }
        return self::$manager;
    }

    // Facade的な薄い委譲
    public static function table(string $table): QueryBuilder
    {
        return self::m()->table($table);
    }

    /** @return list<array<string,mixed>> */
    public static function select(string $sql, array $params = []): array
    {
        return self::m()->select($sql, $params);
    }

    /** @return array<string,mixed>|null */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        return self::m()->selectOne($sql, $params);
    }

    public static function statement(string $sql, array $params = []): int
    {
        return self::m()->statement($sql, $params);
    }

    /** @template T */
    public static function transaction(callable $fn)
    {
        return self::m()->transaction($fn);
    }

    public static function pdo(): PDO
    {
        return self::m()->pdo();
    }
}
