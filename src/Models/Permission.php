<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Permission extends Model
{
    protected static string $table = 'permissions';
    protected static bool $timestamps = false;

    public static function grouped(): array
    {
        $rows = Database::select('SELECT * FROM permissions ORDER BY group_name, name');

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['group_name']][] = $row;
        }

        return $grouped;
    }
}
