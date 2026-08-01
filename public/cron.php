<?php
/**
 * Maintenance entry point for hosting panels that can only schedule a PHP file.
 *
 * Point cron at this file and nothing else — it decides for itself which tasks
 * are due. Run it every 5-10 minutes.
 *
 *   Command:  /usr/local/bin/php /home/USER/public_html/s3/public/cron.php
 *   URL:      https://your-site/cron.php?token=YOUR_CRON_TOKEN
 *
 * The URL form only works once CRON_TOKEN is set in .env; without it, HTTP
 * triggering stays disabled rather than leaving an open endpoint.
 */
declare(strict_types=1);

$basePath = dirname(__DIR__);

require $basePath . '/autoload.php';
require $basePath . '/src/Core/helpers.php';

use App\Console\Scheduler;
use App\Core\App;
use App\Core\Logger;

App::boot($basePath);

$isCli = PHP_SAPI === 'cli';

// Cron runs are short but not instant; the default web limit can be too tight.
@set_time_limit($isCli ? 0 : 120);
@ignore_user_abort(true);

if (!$isCli) {
    $token = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

    if (!Scheduler::tokenMatches($token)) {
        Logger::warning('Rejected cron request', [
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'reason' => Scheduler::token() === '' ? 'CRON_TOKEN not configured' : 'bad token',
        ]);

        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 'forbidden',
                'message' => Scheduler::token() === ''
                    ? 'Cron over HTTP is disabled: set CRON_TOKEN in .env first.'
                    : 'Invalid cron token.',
            ],
        ], JSON_UNESCAPED_SLASHES);

        exit;
    }
}

$budget = isset($_GET['budget']) ? max(5, min(280, (int) $_GET['budget'])) : ($isCli ? 300 : 50);
$force = isset($_GET['force']) || in_array('--force', $argv ?? [], true);

$report = Scheduler::run($budget, $force);

if ($isCli) {
    echo PHP_EOL;

    if ($report['skipped']) {
        echo '  Skipped: ' . $report['reason'] . PHP_EOL . PHP_EOL;
        exit(0);
    }

    if ($report['ran'] === []) {
        echo '  Nothing due.' . PHP_EOL . PHP_EOL;
        exit(0);
    }

    foreach ($report['ran'] as $task => $result) {
        printf('  %-18s %s%s', $task, $result, PHP_EOL);
    }

    printf('%s  Finished in %dms%s%s', PHP_EOL, $report['duration_ms'], PHP_EOL, PHP_EOL);

    exit(0);
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode(['success' => true, 'data' => $report], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
