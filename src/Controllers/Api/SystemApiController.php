<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Cache;
use App\Core\Logger;
use App\Http\Request;
use App\Http\Response;
use App\Models\AuditLog;
use App\Models\IpRule;
use App\Models\Job;
use App\Models\LoginAttempt;
use App\Services\Auth;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\JobService;
use App\Services\MetricsService;
use App\Services\SettingService;
use App\Services\StatsService;

final class SystemApiController extends Controller
{
    /** Unauthenticated liveness/readiness probe. */
    public function health(Request $request): Response
    {
        $health = MetricsService::health();
        $status = $health['status'] === 'fail' ? 503 : 200;

        return Response::json([
            'success' => $health['status'] !== 'fail',
            'data'    => [
                'status'    => $health['status'],
                'checks'    => $health['checks'],
                'version'   => config('app.version'),
                'timestamp' => date('c'),
                'uptime'    => MetricsService::uptime(),
            ],
        ], $status);
    }

    public function ping(Request $request): Response
    {
        return $this->json(['pong' => true, 'time' => date('c')]);
    }

    public function metrics(Request $request): Response
    {
        $payload = [
            'system'     => MetricsService::system(),
            'throughput' => StatsService::throughput($request->int('window', 60)),
            'jobs'       => Job::stats(),
            'cache'      => ['driver' => Cache::driver()],
        ];

        if (Auth::isAdmin()) {
            $payload['stats'] = StatsService::global();
            $payload['top_users'] = StatsService::topUsers(10);
        } else {
            $payload['stats'] = StatsService::forUser($this->userId());
        }

        return $this->json($payload);
    }

    public function statistics(Request $request): Response
    {
        $userId = Auth::isAdmin() && $request->bool('global') ? null : $this->userId();

        return $this->json([
            'summary'   => $userId === null ? StatsService::global() : StatsService::forUser($userId),
            'timeline'  => StatsService::timeline($request->int('days', 14), $userId),
            'by_type'   => StatsService::storageByType($userId),
            'top_files' => StatsService::topFiles(10, $userId),
        ]);
    }

    public function series(Request $request, string $metric): Response
    {
        $this->requireAdmin();

        return $this->json(MetricsService::series($metric, $request->int('hours', 24)));
    }

    // --- Audit logs -----------------------------------------------------

    public function auditLogs(Request $request): Response
    {
        $filters = [
            'q'           => $request->string('q'),
            'action'      => $request->string('action'),
            'status'      => $request->string('status'),
            'entity_type' => $request->string('entity_type'),
            'from'        => $request->string('from'),
            'to'          => $request->string('to'),
        ];

        // Non-admins only ever see their own trail.
        if (!Auth::isAdmin()) {
            $filters['user_id'] = $this->userId();
        } elseif ($request->int('user_id') > 0) {
            $filters['user_id'] = $request->int('user_id');
        }

        $result = AuditLog::search($filters, $this->pageNumber($request), $this->perPage($request, 50));

        return $this->json($result['data'], 200, $this->paginationMeta($result));
    }

    public function appLogs(Request $request): Response
    {
        $this->requireAdmin();

        return $this->json(Logger::tail(
            $this->perPage($request, 100, 500),
            $request->string('level') ?: null,
            $request->string('date') ?: null
        ), 200, ['dates' => Logger::availableDates()]);
    }

    public function loginAttempts(Request $request): Response
    {
        $this->requireAdmin();

        $success = $request->has('success') ? $request->bool('success') : null;

        return $this->json(LoginAttempt::recent($this->perPage($request, 50, 500), $success));
    }

    // --- Jobs -----------------------------------------------------------

    public function jobs(Request $request): Response
    {
        $this->requireAdmin();

        return $this->json([
            'stats' => Job::stats(),
            'types' => JobService::TYPES,
            'jobs'  => JobService::recent($this->perPage($request, 50, 200)),
        ]);
    }

    public function runJob(Request $request, string $type): Response
    {
        $this->requireAdmin();

        if (!array_key_exists($type, JobService::TYPES)) {
            return $this->error('unknown_job', 'Unknown job type.', 422, ['available' => array_keys(JobService::TYPES)]);
        }

        if ($request->bool('queue')) {
            return $this->json(['queued' => true, 'job_id' => JobService::dispatch($type, $request->array('payload'))], 202);
        }

        $result = JobService::execute($type, $request->array('payload'));

        return $this->json($result, $result['ok'] ? 200 : 500);
    }

    public function retryJob(Request $request, string $id): Response
    {
        $this->requireAdmin();

        JobService::retry((int) $id);

        return $this->json(['requeued' => true]);
    }

    public function workQueue(Request $request): Response
    {
        $this->requireAdmin();

        return $this->json(JobService::work($request->string('queue', 'default'), $request->int('max', 25)));
    }

    // --- Backups --------------------------------------------------------

    public function backups(Request $request): Response
    {
        $this->requireAdmin();

        return $this->json(BackupService::list());
    }

    public function createBackup(Request $request): Response
    {
        $this->requireAdmin();

        return $this->json(BackupService::create($request->string('label')), 201);
    }

    public function downloadBackup(Request $request, string $name): Response
    {
        $this->requireAdmin();

        $file = BackupService::resolve($name);
        AuditService::log('backup.download', 'backup', $name, 'Downloaded backup via API');

        return Response::stream(static function () use ($file): void {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                return;
            }
            while (!feof($handle)) {
                echo fread($handle, 262144);
                flush();
            }
            fclose($handle);
        }, 200, [
            'Content-Type'        => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . basename($file) . '"',
            'Content-Length'      => (string) filesize($file),
        ]);
    }

    public function deleteBackup(Request $request, string $name): Response
    {
        $this->requireAdmin();

        BackupService::delete($name);

        return $this->json(['deleted' => true]);
    }

    // --- Settings & IP rules ---------------------------------------------

    public function settings(Request $request): Response
    {
        $this->requireAdmin();

        if ($request->method === 'GET') {
            return $this->json(SettingService::grouped());
        }

        $values = $request->array('settings');

        if ($values === []) {
            return $this->error('no_settings', 'Provide a `settings` object.', 422);
        }

        SettingService::setMany($values, $request->string('group', 'general'));
        AuditService::log('settings.update', 'settings', null, 'Updated settings via API', array_keys($values));

        return $this->json(SettingService::grouped());
    }

    public function ipRules(Request $request): Response
    {
        $this->requireAdmin();

        if ($request->method === 'GET') {
            return $this->json(IpRule::all('id DESC', 500));
        }

        $this->validate($request, [
            'cidr' => 'required|string|max:64',
            'type' => 'required|in:allow,block',
        ]);

        $id = IpRule::create([
            'type'       => $request->string('type'),
            'cidr'       => $request->string('cidr'),
            'scope'      => $request->string('scope', 'global'),
            'note'       => $request->string('note'),
            'created_by' => $this->userId(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Cache::flush();

        return $this->json(IpRule::find($id), 201);
    }

    public function deleteIpRule(Request $request, string $id): Response
    {
        $this->requireAdmin();

        IpRule::deleteById((int) $id);
        Cache::flush();

        return $this->json(['deleted' => true]);
    }
}
