<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Applies database/migrations/*.sql once each, tracked in `migrations`.
 */
final class Migrator
{
    public function __construct(private string $path)
    {
    }

    public function ensureTable(): void
    {
        Database::statement(
            'CREATE TABLE IF NOT EXISTS `migrations` (
                `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(190) NOT NULL,
                `batch`     INT UNSIGNED NOT NULL DEFAULT 1,
                `ran_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `migrations_name_unique` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function pending(): array
    {
        $this->ensureTable();

        $applied = array_column(Database::select('SELECT migration FROM migrations'), 'migration');
        $files = glob($this->path . '/*.sql') ?: [];
        sort($files);

        return array_values(array_filter(
            $files,
            static fn (string $file): bool => !in_array(basename($file), $applied, true)
        ));
    }

    /**
     * @return list<array{migration:string, statements:int}>
     */
    public function run(?callable $onProgress = null): array
    {
        $this->ensureTable();

        $batch = (int) Database::scalar('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations');
        $results = [];

        foreach ($this->pending() as $file) {
            $name = basename($file);
            $sql = (string) file_get_contents($file);
            $statements = $this->split($sql);

            $pdo = Database::pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            Database::insert('migrations', [
                'migration' => $name,
                'batch'     => $batch,
                'ran_at'    => date('Y-m-d H:i:s'),
            ]);

            $results[] = ['migration' => $name, 'statements' => count($statements)];

            if ($onProgress !== null) {
                $onProgress($name, count($statements));
            }
        }

        return $results;
    }

    public function applied(): array
    {
        $this->ensureTable();

        return Database::select('SELECT migration, batch, ran_at FROM migrations ORDER BY id');
    }

    /**
     * Split on semicolons that are not inside quotes or comments.
     *
     * @return list<string>
     */
    private function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($inComment) {
                if ($char === "\n") {
                    $inComment = false;
                    $buffer .= $char;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick && $char === '-' && $next === '-') {
                $inComment = true;
                $i++;
                continue;
            }

            if (!$inDouble && !$inBacktick && $char === "'" && ($sql[$i - 1] ?? '') !== '\\') {
                $inSingle = !$inSingle;
            } elseif (!$inSingle && !$inBacktick && $char === '"' && ($sql[$i - 1] ?? '') !== '\\') {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble && $char === '`') {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
