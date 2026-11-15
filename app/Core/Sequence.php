<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Gap-tolerant, concurrency-safe counters for human-readable numbers (USR-0001, REG-000001, …).
 * The row lock taken by INSERT … ON DUPLICATE KEY UPDATE serialises concurrent callers.
 */
final class Sequence
{
    public static function next(string $key): int
    {
        Database::execute(
            'INSERT INTO sequences (seq_key, last_value, updated_at) VALUES (:k, LAST_INSERT_ID(1), UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1), updated_at = UTC_TIMESTAMP(3)',
            ['k' => $key]
        );
        return (int) Database::pdo()->lastInsertId();
    }
}
