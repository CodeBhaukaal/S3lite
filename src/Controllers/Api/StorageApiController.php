<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Models\StorageBackend;
use App\Services\JobService;
use App\Services\StorageBackendService;
use App\Storage\SftpDriver;
use App\Storage\StorageManager;

/**
 * Manage where files are stored. Credentials go in but never come back out.
 */
final class StorageApiController extends Controller
{
    public function index(Request $request): Response
    {
        $usage = StorageBackendService::usageBySlug();

        $backends = array_map(static function (array $backend) use ($usage): array {
            $public = StorageBackend::publicArray($backend);
            $public['usage'] = $usage[$public['slug']] ?? ['files' => 0, 'versions' => 0, 'bytes' => 0];

            return $public;
        }, StorageBackendService::list());

        return $this->json([
            'backends' => $backends,
            'default'  => StorageManager::defaultSlug(),
            'drivers'  => StorageBackend::DRIVERS,
            'support'  => [
                'ftp'  => extension_loaded('ftp'),
                'ftps' => extension_loaded('ftp') && function_exists('ftp_ssl_connect'),
                'sftp' => SftpDriver::isSupported(),
            ],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $backend = $this->resolve($id);
        $public = StorageBackend::publicArray($backend);
        $public['usage'] = StorageBackendService::usage((string) $backend['slug']);

        return $this->json($public);
    }

    public function store(Request $request): Response
    {
        $backend = StorageBackendService::create($request->all());

        return $this->json(StorageBackend::publicArray($backend), 201);
    }

    public function update(Request $request, string $id): Response
    {
        $backend = $this->resolve($id);
        $updated = StorageBackendService::update((int) $backend['id'], $request->all());

        return $this->json(StorageBackend::publicArray($updated));
    }

    public function destroy(Request $request, string $id): Response
    {
        $backend = $this->resolve($id);
        StorageBackendService::delete((int) $backend['id']);

        return $this->json(['deleted' => true]);
    }

    public function test(Request $request, string $id): Response
    {
        $backend = $this->resolve($id);
        $result = StorageBackendService::test((int) $backend['id']);

        return $this->json($result, $result['ok'] ? 200 : 502);
    }

    public function makeDefault(Request $request, string $id): Response
    {
        $backend = $this->resolve($id);

        return $this->json(StorageBackend::publicArray(
            StorageBackendService::makeDefault((int) $backend['id'])
        ));
    }

    /**
     * Move files off this backend. Synchronous by default so scripts can loop
     * until `remaining` hits zero; pass `queue=true` to hand it to the worker.
     */
    public function migrate(Request $request, string $id): Response
    {
        $backend = $this->resolve($id);
        $target = $request->string('to');

        if ($target === '') {
            return $this->error('missing_target', 'Send a `to` backend slug.', 422);
        }

        StorageBackendService::resolveForUpload($target);

        $payload = [
            'from'  => (string) $backend['slug'],
            'to'    => $target,
            'limit' => max(1, min(1000, $request->int('limit', 200))),
        ];

        if ($request->bool('queue')) {
            return $this->json(['queued' => true, 'job_id' => JobService::dispatch('storage.migrate', $payload)], 202);
        }

        return $this->json(StorageBackendService::migrate($payload['from'], $payload['to'], $payload['limit']));
    }

    private function resolve(string $id): array
    {
        $backend = StorageBackend::resolve($id) ?? StorageBackend::findBySlug($id);

        if ($backend === null) {
            throw new \App\Http\Exceptions\HttpException(404, 'Storage backend not found.', 'backend_not_found');
        }

        return $backend;
    }
}
