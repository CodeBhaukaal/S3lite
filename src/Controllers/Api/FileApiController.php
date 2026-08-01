<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Config;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\FileRecord;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Services\DownloadService;
use App\Services\FileService;
use App\Services\MultipartService;
use App\Services\ShareService;
use App\Support\Str;

final class FileApiController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->userId();

        $filters = [
            'user_id'   => $userId,
            'q'         => $request->string('q'),
            'mime'      => $request->string('mime'),
            'extension' => $request->string('extension'),
            'tag'       => $request->string('tag'),
            'trashed'   => $request->bool('trashed'),
            'shared'    => $request->bool('shared'),
            'min_size'  => $request->int('min_size'),
            'max_size'  => $request->int('max_size'),
            'from'      => $request->string('from'),
            'to'        => $request->string('to'),
        ];

        if ($request->has('folder_id')) {
            $param = $request->string('folder_id');

            if ($param === '' || $param === 'root') {
                $filters['folder_id'] = null;
            } else {
                $folder = Folder::ownedBy($param, $userId);
                if ($folder === null) {
                    return $this->error('folder_not_found', 'Folder not found.', 404);
                }
                $filters['folder_id'] = (int) $folder['id'];
            }
        }

        $result = FileRecord::search(
            $filters,
            $this->pageNumber($request),
            $this->perPage($request, 25),
            $request->string('sort', 'created_at'),
            $request->string('direction', 'desc')
        );

        return $this->json(
            array_map(fn (array $f): array => FileRecord::publicArray($f, $this->downloadUrl($f)), $result['data']),
            200,
            $this->paginationMeta($result)
        );
    }

    public function show(Request $request, string $id): Response
    {
        $file = $this->resolve($id, true);

        return $this->json([
            'file'     => FileRecord::publicArray($file, $this->downloadUrl($file)),
            'versions' => array_map([FileVersion::class, 'publicArray'], FileVersion::forFile((int) $file['id'])),
            'shares'   => array_map([\App\Models\Share::class, 'publicArray'], \App\Models\Share::forFile((int) $file['id'])),
        ]);
    }

    public function upload(Request $request): Response
    {
        $userId = $this->userId();

        $files = $request->fileList('files');
        if ($files === []) {
            $single = $request->file('file');
            if ($single !== null) {
                $files = [$single];
            }
        }

        // Raw-body upload: PUT/POST the bytes with X-File-Name.
        if ($files === [] && $request->rawBody === '' && (int) ($request->server['CONTENT_LENGTH'] ?? 0) > 0) {
            return $this->rawUpload($request);
        }

        if ($files === []) {
            return $this->error('no_file', 'Send a multipart form field named `file` (or `files[]`).', 422);
        }

        $folderId = $this->folderIdFrom($request, $userId);
        $tags = $this->tagsFrom($request);

        $stored = [];
        $errors = [];

        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = ['name' => $file['name'], 'code' => (int) $file['error']];
                continue;
            }

            try {
                $result = FileService::store($userId, [
                    'path' => $file['tmp_name'],
                    'name' => $file['name'],
                    'size' => (int) $file['size'],
                ], ['folder_id' => $folderId, 'tags' => $tags, 'source' => 'api']);

                $stored[] = [
                    'file'      => FileRecord::publicArray($result['file'], $this->downloadUrl($result['file'])),
                    'duplicate' => $result['duplicate'],
                ];
            } catch (HttpException $e) {
                $errors[] = ['name' => $file['name'], 'code' => $e->errorCode(), 'message' => $e->getMessage()];
            }
        }

        if ($stored === [] && $errors !== []) {
            return $this->error('upload_failed', 'No files could be stored.', 422, $errors);
        }

        return $this->json(['uploaded' => $stored, 'errors' => $errors], 201);
    }

    /** Stream a raw request body straight to storage. */
    private function rawUpload(Request $request): Response
    {
        $name = (string) $request->header('X-File-Name', '');

        if ($name === '') {
            return $this->error('missing_filename', 'Raw uploads require an X-File-Name header.', 422);
        }

        $tmp = rtrim((string) Config::get('storage.tmp_path'), '/\\') . '/raw-' . Str::random(16);

        $in = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');

        if ($in === false || $out === false) {
            return $this->error('upload_failed', 'Unable to read the request body.', 500);
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        $result = FileService::store($this->userId(), [
            'path' => $tmp,
            'name' => rawurldecode($name),
            'size' => (int) filesize($tmp),
        ], [
            'folder_id' => $this->folderIdFrom($request, $this->userId()),
            'source'    => 'api',
        ]);

        return $this->json([
            'file'      => FileRecord::publicArray($result['file'], $this->downloadUrl($result['file'])),
            'duplicate' => $result['duplicate'],
        ], 201);
    }

    public function update(Request $request, string $id): Response
    {
        $file = $this->resolve($id);
        $userId = $this->userId();

        if ($request->filled('name')) {
            $file = FileService::rename($userId, (int) $file['id'], $request->string('name'));
        }

        if ($request->has('folder_id')) {
            $file = FileService::move($userId, (int) $file['id'], $this->folderIdFrom($request, $userId));
        }

        if ($request->has('tags')) {
            $file = FileService::setTags($userId, (int) $file['id'], $this->tagsFrom($request));
        }

        return $this->json(FileRecord::publicArray($file, $this->downloadUrl($file)));
    }

    public function destroy(Request $request, string $id): Response
    {
        $file = $this->resolve($id, true);
        $permanent = $request->bool('permanent');

        if ($permanent) {
            FileService::purge($this->userId(), (int) $file['id']);
        } else {
            FileService::trash($this->userId(), (int) $file['id']);
        }

        return $this->json(['deleted' => true, 'permanent' => $permanent]);
    }

    public function restore(Request $request, string $id): Response
    {
        $file = $this->resolve($id, true);

        return $this->json(FileRecord::publicArray(
            FileService::restore($this->userId(), (int) $file['id'])
        ));
    }

    public function copy(Request $request, string $id): Response
    {
        $file = $this->resolve($id);

        $copy = FileService::copy($this->userId(), (int) $file['id'], $this->folderIdFrom($request, $this->userId()));

        return $this->json(FileRecord::publicArray($copy, $this->downloadUrl($copy)), 201);
    }

    public function download(Request $request, string $id): Response
    {
        $file = $this->resolve($id, true);

        return DownloadService::serve($request, $file, $request->bool('inline'), null, 'api');
    }

    public function versions(Request $request, string $id): Response
    {
        $file = $this->resolve($id, true);

        return $this->json(array_map([FileVersion::class, 'publicArray'], FileVersion::forFile((int) $file['id'])));
    }

    public function restoreVersion(Request $request, string $id, string $version): Response
    {
        $file = $this->resolve($id, true);

        return $this->json(FileRecord::publicArray(
            FileService::restoreVersion($this->userId(), (int) $file['id'], (int) $version)
        ));
    }

    public function downloadVersion(Request $request, string $id, string $version): Response
    {
        $file = $this->resolve($id, true);
        $record = FileVersion::findVersion((int) $file['id'], (int) $version);

        if ($record === null) {
            return $this->error('version_not_found', 'Version not found.', 404);
        }

        $synthetic = array_merge($file, [
            'storage_path' => $record['storage_path'],
            'size'         => $record['size'],
            'checksum'     => $record['checksum'],
            'mime'         => $record['mime'],
            'name'         => pathinfo((string) $file['name'], PATHINFO_FILENAME) . '-v' . $version
                . ($file['extension'] === '' ? '' : '.' . $file['extension']),
        ]);

        return DownloadService::serve($request, $synthetic, false, null, 'api');
    }

    // --- Sharing --------------------------------------------------------

    public function share(Request $request, string $id): Response
    {
        $file = $this->resolve($id);

        $share = ShareService::createForFile($this->userId(), (int) $file['id'], [
            'type'          => $request->string('type', 'permanent'),
            'password'      => $request->string('password') ?: null,
            'expires_at'    => $request->string('expires_at') ?: null,
            'expires_in'    => $request->has('expires_in') ? $request->int('expires_in') : null,
            'max_downloads' => $request->has('max_downloads') ? $request->int('max_downloads') : null,
            'allow_preview' => $request->bool('allow_preview', true),
        ]);

        return $this->json(\App\Models\Share::publicArray($share), 201);
    }

    public function unshare(Request $request, string $id): Response
    {
        $file = $this->resolve($id, true);

        return $this->json(['revoked' => ShareService::revokeAllForFile($this->userId(), (int) $file['id'])]);
    }

    public function temporaryUrl(Request $request, string $id): Response
    {
        $file = $this->resolve($id);
        $ttl = max(60, min(604800, $request->int('ttl', 3600)));

        return $this->json([
            'url'        => ShareService::temporaryUrl($file, $ttl),
            'expires_at' => date('c', time() + $ttl),
            'ttl'        => $ttl,
        ]);
    }

    // --- Multipart ------------------------------------------------------

    public function multipartInit(Request $request): Response
    {
        $this->validate($request, [
            'filename'   => 'required|string|max:255',
            'total_size' => 'required|int|min:1',
        ]);

        return $this->json(MultipartService::init(
            $this->userId(),
            $request->string('filename'),
            $request->int('total_size'),
            $this->folderIdFrom($request, $this->userId()),
            $request->string('mime') ?: null,
            $request->has('part_size') ? $request->int('part_size') : null
        ), 201);
    }

    public function multipartPart(Request $request, string $uploadId): Response
    {
        $partNumber = $request->int('part_number', (int) $request->header('X-Part-Number', '0'));

        if ($partNumber < 1) {
            return $this->error('invalid_part_number', 'Provide part_number (or the X-Part-Number header).', 422);
        }

        $file = $request->file('part') ?? $request->file('file');

        if ($file !== null) {
            $path = $file['tmp_name'];
            $size = (int) $file['size'];
        } else {
            // Raw body part.
            $path = rtrim((string) Config::get('storage.tmp_path'), '/\\') . '/part-' . Str::random(16);
            $in = fopen('php://input', 'rb');
            $out = fopen($path, 'wb');

            if ($in === false || $out === false) {
                return $this->error('invalid_part', 'No part data was received.', 422);
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $size = (int) filesize($path);

            if ($size === 0) {
                @unlink($path);

                return $this->error('invalid_part', 'The part was empty.', 422);
            }
        }

        return $this->json(MultipartService::uploadPart($this->userId(), $uploadId, $partNumber, [
            'path' => $path,
            'size' => $size,
        ]));
    }

    public function multipartStatus(Request $request, string $uploadId): Response
    {
        return $this->json(MultipartService::status($this->userId(), $uploadId));
    }

    public function multipartComplete(Request $request, string $uploadId): Response
    {
        $result = MultipartService::complete($this->userId(), $uploadId, [
            'checksum' => $request->string('checksum') ?: null,
            'tags'     => $this->tagsFrom($request),
        ]);

        return $this->json([
            'file'      => FileRecord::publicArray($result['file'], $this->downloadUrl($result['file'])),
            'duplicate' => $result['duplicate'],
        ], 201);
    }

    public function multipartAbort(Request $request, string $uploadId): Response
    {
        MultipartService::abort($this->userId(), $uploadId);

        return $this->json(['aborted' => true]);
    }

    public function multipartList(Request $request): Response
    {
        return $this->json(MultipartService::listPending($this->userId()));
    }

    // --- Helpers --------------------------------------------------------

    public function tags(Request $request): Response
    {
        return $this->json(FileRecord::allTags($this->userId()));
    }

    private function downloadUrl(array $file): string
    {
        return url('api/v1/files/' . $file['uuid'] . '/download');
    }

    private function folderIdFrom(Request $request, int $userId): ?int
    {
        $param = $request->string('folder_id');

        if ($param === '' || $param === 'root' || $param === '0') {
            return null;
        }

        $folder = Folder::ownedBy($param, $userId);

        if ($folder === null) {
            throw new HttpException(404, 'Destination folder not found.', 'folder_not_found');
        }

        return (int) $folder['id'];
    }

    /** @return list<string> */
    private function tagsFrom(Request $request): array
    {
        $tags = $request->input('tags');

        if (is_array($tags)) {
            return array_values(array_filter(array_map('strval', $tags)));
        }

        if (is_string($tags) && $tags !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $tags))));
        }

        return [];
    }

    private function resolve(string $id, bool $includeTrashed = false): array
    {
        $file = FileRecord::ownedBy($id, $this->userId(), $includeTrashed);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        return $file;
    }
}
