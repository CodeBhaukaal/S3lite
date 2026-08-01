<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Minimal active-record-ish base. Models return plain arrays; every value that
 * reaches SQL goes through bound parameters.
 */
abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    /** Columns automatically json_decode()d on read and json_encode()d on write. */
    protected static array $jsonColumns = [];
    protected static bool $timestamps = true;
    protected static bool $softDeletes = false;

    public static function table(): string
    {
        return static::$table;
    }

    public static function find(int|string $id): ?array
    {
        $row = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1', static::$table, static::$primaryKey),
            [$id]
        );

        return $row === null ? null : static::hydrate($row);
    }

    public static function findBy(string $column, mixed $value): ?array
    {
        $row = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1', static::$table, $column),
            [$value]
        );

        return $row === null ? null : static::hydrate($row);
    }

    public static function findWhere(array $conditions, string $order = ''): ?array
    {
        [$where, $bindings] = static::buildWhere($conditions);

        $sql = sprintf('SELECT * FROM `%s` WHERE %s', static::$table, $where);
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        $sql .= ' LIMIT 1';

        $row = Database::selectOne($sql, $bindings);

        return $row === null ? null : static::hydrate($row);
    }

    public static function all(string $order = 'id DESC', int $limit = 500): array
    {
        $rows = Database::select(
            sprintf('SELECT * FROM `%s` ORDER BY %s LIMIT %d', static::$table, $order, $limit)
        );

        return array_map([static::class, 'hydrate'], $rows);
    }

    public static function where(array $conditions, string $order = 'id DESC', int $limit = 500, int $offset = 0): array
    {
        [$where, $bindings] = static::buildWhere($conditions);

        $sql = sprintf(
            'SELECT * FROM `%s` WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
            static::$table,
            $where,
            $order,
            $limit,
            $offset
        );

        return array_map([static::class, 'hydrate'], Database::select($sql, $bindings));
    }

    public static function count(array $conditions = []): int
    {
        if ($conditions === []) {
            return (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s`', static::$table));
        }

        [$where, $bindings] = static::buildWhere($conditions);

        return (int) Database::scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', static::$table, $where),
            $bindings
        );
    }

    public static function exists(array $conditions): bool
    {
        return static::count($conditions) > 0;
    }

    public static function create(array $data): int
    {
        $data = static::prepare($data);

        if (static::$timestamps) {
            $now = date('Y-m-d H:i:s');
            $data['created_at'] ??= $now;
            $data['updated_at'] ??= $now;
        }

        return Database::insert(static::$table, $data);
    }

    public static function updateById(int|string $id, array $data): int
    {
        $data = static::prepare($data);

        if (static::$timestamps) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        if ($data === []) {
            return 0;
        }

        return Database::update(
            static::$table,
            $data,
            sprintf('`%s` = :pk_id', static::$primaryKey),
            ['pk_id' => $id]
        );
    }

    public static function deleteById(int|string $id): int
    {
        return Database::delete(
            static::$table,
            sprintf('`%s` = ?', static::$primaryKey),
            [$id]
        );
    }

    public static function softDelete(int|string $id): int
    {
        return static::updateById($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public static function restore(int|string $id): int
    {
        return static::updateById($id, ['deleted_at' => null]);
    }

    /**
     * @return array{data:list<array>, total:int, page:int, per_page:int, last_page:int}
     */
    public static function paginate(array $conditions = [], int $page = 1, int $perPage = 20, string $order = 'id DESC'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $total = static::count($conditions);
        $rows = $conditions === []
            ? array_map([static::class, 'hydrate'], Database::select(
                sprintf('SELECT * FROM `%s` ORDER BY %s LIMIT %d OFFSET %d', static::$table, $order, $perPage, $offset)
            ))
            : static::where($conditions, $order, $perPage, $offset);

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Supported condition shapes:
     *   ['status' => 'active']            => `status` = ?
     *   ['deleted_at' => null]            => `deleted_at` IS NULL
     *   ['id' => ['in', [1,2,3]]]         => `id` IN (?,?,?)
     *   ['size' => ['>', 100]]            => `size` > ?
     *   ['name' => ['like', '%x%']]       => `name` LIKE ?
     *   ['__raw' => ['a = ? OR b = ?', [1, 2]]]
     *
     * @return array{0:string, 1:array}
     */
    protected static function buildWhere(array $conditions, string $alias = ''): array
    {
        $clauses = [];
        $bindings = [];
        $prefix = $alias === '' ? '' : $alias . '.';

        foreach ($conditions as $rawColumn => $value) {
            if ($rawColumn === '__raw' && is_array($value)) {
                $clauses[] = '(' . $value[0] . ')';
                foreach ((array) ($value[1] ?? []) as $binding) {
                    $bindings[] = $binding;
                }
                continue;
            }

            $column = $prefix . '`' . str_replace('`', '', (string) $rawColumn) . '`';

            if ($value === null) {
                $clauses[] = $column . ' IS NULL';
                continue;
            }

            if (is_array($value) && count($value) === 2 && is_string($value[0])) {
                $operator = strtolower($value[0]);
                $operand = $value[1];

                if ($operator === 'in' || $operator === 'not in') {
                    $list = (array) $operand;
                    if ($list === []) {
                        $clauses[] = $operator === 'in' ? '1 = 0' : '1 = 1';
                        continue;
                    }
                    $placeholders = implode(', ', array_fill(0, count($list), '?'));
                    $clauses[] = sprintf('%s %s (%s)', $column, strtoupper($operator), $placeholders);
                    foreach ($list as $item) {
                        $bindings[] = $item;
                    }
                    continue;
                }

                if ($operator === 'not null') {
                    $clauses[] = $column . ' IS NOT NULL';
                    continue;
                }

                if (in_array($operator, ['=', '!=', '<>', '>', '<', '>=', '<=', 'like', 'not like'], true)) {
                    $clauses[] = sprintf('%s %s ?', $column, strtoupper($operator));
                    $bindings[] = $operand;
                    continue;
                }
            }

            $clauses[] = $column . ' = ?';
            $bindings[] = $value;
        }

        return [$clauses === [] ? '1 = 1' : implode(' AND ', $clauses), $bindings];
    }

    protected static function hydrate(array $row): array
    {
        foreach (static::$jsonColumns as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $decoded = is_string($row[$column]) ? json_decode($row[$column], true) : null;
            $row[$column] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    protected static function prepare(array $data): array
    {
        foreach (static::$jsonColumns as $column) {
            if (array_key_exists($column, $data) && is_array($data[$column])) {
                $data[$column] = json_encode($data[$column], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = $value ? 1 : 0;
            }
        }

        return $data;
    }
}
