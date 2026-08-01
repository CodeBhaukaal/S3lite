<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. Every query goes through prepared statements, which is the
 * project's single line of defence against SQL injection.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $queryCount = 0;
    private static float $queryTime = 0.0;

    public static function connect(?array $config = null): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config ??= Config::get('database', []);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $config['username'], $config['password'], $config['options'] ?? []);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }

    /** Connect without selecting a database (used by the installer). */
    public static function connectServer(array $config): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset'] ?? 'utf8mb4');

        return new PDO($dsn, $config['username'], $config['password'], $config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::connect();
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function query(string $sql, array $bindings = []): PDOStatement
    {
        $start = microtime(true);

        $stmt = self::pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }

        $stmt->execute();

        self::$queryCount++;
        self::$queryTime += microtime(true) - $start;

        return $stmt;
    }

    public static function select(string $sql, array $bindings = []): array
    {
        return self::query($sql, $bindings)->fetchAll();
    }

    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::query($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::query($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function statement(string $sql, array $bindings = []): int
    {
        return self::query($sql, $bindings)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        self::query($sql, $data);

        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $bindings = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = sprintf('`%s` = :set_%s', $column, $column);
        }

        $params = [];
        foreach ($data as $column => $value) {
            $params['set_' . $column] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);

        return self::statement($sql, array_merge($params, $bindings));
    }

    public static function delete(string $table, string $where, array $bindings = []): int
    {
        return self::statement(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $bindings);
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();

        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function tableExists(string $table): bool
    {
        $db = Config::get('database.database');
        $count = self::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$db, $table]
        );

        return (int) $count > 0;
    }

    public static function stats(): array
    {
        return ['queries' => self::$queryCount, 'time_ms' => round(self::$queryTime * 1000, 2)];
    }
}
