<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Thin PDO wrapper. Runtime requests use the restricted `app` account; CLI tools may
 * inject the migration account with Database::use().
 * All sessions run in UTC with strict SQL mode and native (non-emulated) prepared statements.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(string $user, string $password, bool $selectDatabase = true): PDO
    {
        $c = Config::get('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=utf8mb4',
            $c['host'],
            $c['port'],
            $selectDatabase ? 'dbname=' . $c['name'] . ';' : ''
        );
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public static function use(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            self::$pdo = self::connect($c['app_user'], $c['app_password']);
        }
        return self::$pdo;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::run($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @return int affected rows */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** @return int last insert id */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Runs the callback inside a transaction. One service calling another that also wants a
     * transaction is normal here (stores issuing medicine tops up the pharmacy shelf), so an
     * inner call simply joins the outer one: the outermost call owns the commit or rollback.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Builds a named placeholder list for IN (...) clauses.
     * @return array{0:string,1:array}
     */
    public static function in(string $prefix, array $values): array
    {
        $names = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $names[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $v;
        }
        return [implode(', ', $names) ?: 'NULL', $params];
    }

    private static function run(string $sql, array $params): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($name) ? $name + 1 : ':' . ltrim((string) $name, ':'), $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }
}
