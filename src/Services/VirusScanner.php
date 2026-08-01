<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Pluggable scan hook. Points at any CLI scanner (clamscan, clamdscan, …) via
 * VIRUS_SCAN_COMMAND, with `{file}` substituted for the path.
 */
final class VirusScanner
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const SKIPPED = 'skipped';
    public const ERROR = 'error';

    public static function enabled(): bool
    {
        return (bool) SettingService::get('virus_scan_enabled', Config::get('storage.virus_scan.enabled', false));
    }

    /**
     * @return array{result:string, detail:string}
     */
    public static function scan(string $path): array
    {
        if (!self::enabled()) {
            return ['result' => self::SKIPPED, 'detail' => 'Scanning disabled'];
        }

        // The EICAR test string is always caught, even without an external scanner.
        if (self::containsEicar($path)) {
            return ['result' => self::INFECTED, 'detail' => 'EICAR-Test-Signature'];
        }

        $command = (string) SettingService::get('virus_scan_command', Config::get('storage.virus_scan.command', ''));

        if (trim($command) === '') {
            return ['result' => self::SKIPPED, 'detail' => 'No scan command configured'];
        }

        $full = str_replace('{file}', escapeshellarg($path), $command);

        $output = [];
        $exitCode = 0;
        @exec($full . ' 2>&1', $output, $exitCode);

        $detail = trim(implode("\n", array_slice($output, -5)));

        return match ($exitCode) {
            0       => ['result' => self::CLEAN, 'detail' => $detail],
            1       => ['result' => self::INFECTED, 'detail' => $detail],
            default => self::logAndError($detail, $exitCode),
        };
    }

    private static function logAndError(string $detail, int $exitCode): array
    {
        Logger::warning('Virus scan returned an unexpected exit code.', ['exit' => $exitCode, 'detail' => $detail]);

        return ['result' => self::ERROR, 'detail' => $detail];
    }

    private static function containsEicar(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 1024);
        fclose($handle);

        $signature = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

        return str_contains($head, $signature);
    }
}
