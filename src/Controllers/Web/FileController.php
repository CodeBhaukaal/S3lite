<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Config;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\FileRecord;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\Share;
use App\Services\DownloadService;
use App\Services\FileService;
use App\Services\FolderService;
use App\Services\SettingService;
use App\Support\SignedUrl;

final class FileController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->userId();

        $folderId = null;
        $folderParam = $request->string('folder');

        if ($folderParam !== '' && $folderParam !== 'root') {
            $folder = Folder::ownedBy($folderParam, $userId);

            if ($folder === null) {
                return $this->redirect('/files', 'error', 'That folder could not be found.');
            }

            $folderId = (int) $folder['id'];
        }

        $filters = [
            'q'         => $request->string('q'),
            'mime'      => $request->string('type'),
            'tag'       => $request->string('tag'),
            'extension' => $request->string('ext'),
            'from'      => $request->string('from'),
            'to'        => $request->string('to'),
        ];

        $result = FolderService::browse(
            $userId,
            $folderId,
            $filters,
            $this->pageNumber($request),
            $this->perPage($request, 24),
            $request->string('sort', 'created_at'),
            $request->string('dir', 'desc')
        );

        return $this->view('app.files', [
            'folder'      => $result['folder'],
            'folders'     => $result['folders'],
            'files'       => $result['files'],
            'breadcrumbs' => $result['breadcrumbs'],
            'filters'     => $filters,
            'sort'        => $request->string('sort', 'created_at'),
            'direction'   => $request->string('dir', 'desc'),
            'viewMode'    => $request->string('view', 'grid') === 'list' ? 'list' : 'grid',
            'tags'        => FileRecord::allTags($userId),
            'folderTree'  => FolderService::tree($userId),
            'maxUpload'   => (int) SettingService::get('max_upload_size', Config::get('storage.max_upload')),
            'chunkSize'   => (int) SettingService::get('chunk_size', Config::get('storage.chunk_size')),
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

        if ($files === []) {
            return $this->error('no_file', 'No file was received. Check upload_max_filesize and post_max_size.', 422);
        }

        $folderId = null;
        $folderParam = $request->string('folder_id');
        if ($folderParam !== '' && $folderParam !== 'root' && $folderParam !== '0') {
            $folder = Folder::ownedBy($folderParam, $userId);
            if ($folder === null) {
                return $this->error('folder_not_found', 'Destination folder not found.', 404);
            }
            $folderId = (int) $folder['id'];
        }

        $tags = array_filter(array_map('trim', explode(',', $request->string('tags'))));

        $stored = [];
        $errors = [];

        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = ['name' => $file['name'], 'message' => $this->uploadErrorMessage((int) $file['error'])];
                continue;
            }

            try {
                $result = FileService::store($userId, [
                    'path' => $file['tmp_name'],
                    'name' => $file['name'],
                    'size' => (int) $file['size'],
                ], [
                    'folder_id' => $folderId,
                    'tags'      => $tags,
                    'source'    => 'web',
                ]);

                $stored[] = [
                    'file'      => FileRecord::publicArray($result['file']),
                    'duplicate' => $result['duplicate'],
                    'message'   => $result['message'],
                ];
            } catch (HttpException $e) {
                $errors[] = ['name' => $file['name'], 'message' => $e->getMessage()];
            }
        }

        if ($request->wantsJson()) {
            return $this->json([
                'uploaded' => $stored,
                'errors'   => $errors,
            ], $stored === [] && $errors !== [] ? 422 : 200);
        }

        if ($errors !== []) {
            return $this->back($request, 'error', count($errors) . ' file(s) could not be uploaded: ' . $errors[0]['message']);
        }

        return $this->back($request, 'success', count($stored) . ' file(s) uploaded.');
    }

    public function show(Request $request, string $id): Response
    {
        $userId = $this->userId();
        $file = FileRecord::ownedBy($id, $userId, true);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        $textPreview = null;
        if (in_array(FileRecord::kind($file), ['text'], true) && (int) $file['size'] < 1048576) {
            $textPreview = DownloadService::previewText($file);
        }

        return $this->view('app.file-detail', [
            'file'        => $file,
            'versions'    => FileVersion::forFile((int) $file['id']),
            'shares'      => Share::forFile((int) $file['id']),
            'folder'      => $file['folder_id'] === null ? null : Folder::find((int) $file['folder_id']),
            'breadcrumbs' => Folder::breadcrumbs($file['folder_id'] === null ? null : (int) $file['folder_id']),
            'textPreview' => $textPreview,
            'folderTree'  => FolderService::tree($userId),
            'signedUrl'   => SignedUrl::sign('/download/' . $file['uuid'], ['uuid' => $file['uuid']], 3600),
        ]);
    }

    public function download(Request $request, string $id): Response
    {
        $file = FileRecord::ownedBy($id, $this->userId(), true);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        return DownloadService::serve($request, $file, false, null, 'web');
    }

    public function preview(Request $request, string $id): Response
    {
        $file = FileRecord::ownedBy($id, $this->userId(), true);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        return DownloadService::serve($request, $file, true, null, 'web');
    }

    /** Signed, expiring download that does not require a session. */
    public function signedDownload(Request $request, string $uuid): Response
    {
        if (!SignedUrl::verify('/download/' . $uuid, $request->query)) {
            throw new HttpException(403, 'This download link is invalid or has expired.', 'invalid_signature');
        }

        $file = FileRecord::findByUuid($uuid);

        if ($file === null || $file['deleted_at'] !== null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        return DownloadService::serve($request, $file, false, null, 'signed');
    }

    public function rename(Request $request, string $id): Response
    {
        $file = $this->resolveOwned($id);
        $this->validate($request, ['name' => 'required|string|max:255']);

        FileService::rename($this->userId(), (int) $file['id'], $request->string('name'));

        return $this->respond($request, 'File renamed.');
    }

    public function move(Request $request, string $id): Response
    {
        $file = $this->resolveOwned($id);
        $folderParam = $request->string('folder_id');

        $folderId = null;
        if ($folderParam !== '' && $folderParam !== 'root' && $folderParam !== '0') {
            $folder = Folder::ownedBy($folderParam, $this->userId());
            if ($folder === null) {
                return $this->error('folder_not_found', 'Destination folder not found.', 404);
            }
            $folderId = (int) $folder['id'];
        }

        FileService::move($this->userId(), (int) $file['id'], $folderId);

        return $this->respond($request, 'File moved.');
    }

    public function copy(Request $request, string $id): Response
    {
        $file = $this->resolveOwned($id);

        FileService::copy($this->userId(), (int) $file['id']);

        return $this->respond($request, 'File copied.');
    }

    public function tags(Request $request, string $id): Response
    {
        $file = $this->resolveOwned($id);

        $tags = $request->has('tags') && is_array($request->input('tags'))
            ? $request->array('tags')
            : array_filter(array_map('trim', explode(',', $request->string('tags'))));

        FileService::setTags($this->userId(), (int) $file['id'], $tags);

        return $this->respond($request, 'Tags updated.');
    }

    public function trash(Request $request, string $id): Response
    {
        $file = $this->resolveOwned($id);

        FileService::trash($this->userId(), (int) $file['id']);

        if ($request->wantsJson()) {
            return $this->json(['trashed' => true]);
        }

        return $this->redirect('/files' . ($file['folder_id'] === null ? '' : '?folder=' . $file['folder_id']), 'success', 'Moved to trash.');
    }

    public function restoreVersion(Request $request, string $id, string $version): Response
    {
        $file = $this->resolveOwned($id, true);

        FileService::restoreVersion($this->userId(), (int) $file['id'], (int) $version);

        return $this->respond($request, 'Version restored.');
    }

    public function bulk(Request $request): Response
    {
        $userId = $this->userId();
        $ids = $request->array('ids');
        $action = $request->string('action');

        if ($ids === []) {
            return $this->error('no_selection', 'No files were selected.', 422);
        }

        $done = 0;
        $failed = [];

        foreach ($ids as $rawId) {
            $file = FileRecord::ownedBy((string) $rawId, $userId, true);

            if ($file === null) {
                $failed[] = ['id' => $rawId, 'message' => 'Not found'];
                continue;
            }

            try {
                match ($action) {
                    'trash'   => FileService::trash($userId, (int) $file['id']),
                    'restore' => FileService::restore($userId, (int) $file['id']),
                    'purge'   => FileService::purge($userId, (int) $file['id']),
                    'copy'    => FileService::copy($userId, (int) $file['id']),
                    'move'    => FileService::move($userId, (int) $file['id'], $this->targetFolderId($request, $userId)),
                    default   => throw new HttpException(422, 'Unknown bulk action.', 'invalid_action'),
                };
                $done++;
            } catch (HttpException $e) {
                $failed[] = ['id' => $rawId, 'message' => $e->getMessage()];
            }
        }

        if ($request->wantsJson()) {
            return $this->json(['processed' => $done, 'failed' => $failed]);
        }

        return $this->back($request, $failed === [] ? 'success' : 'warning', "{$done} file(s) processed.");
    }

    private function targetFolderId(Request $request, int $userId): ?int
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

    private function resolveOwned(string $id, bool $includeTrashed = false): array
    {
        $file = FileRecord::ownedBy($id, $this->userId(), $includeTrashed);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        return $file;
    }

    private function respond(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return $this->json(['message' => $message]);
        }

        return $this->back($request, 'success', $message);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted.',
            UPLOAD_ERR_NO_FILE    => 'No file was received.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default               => 'The upload failed.',
        };
    }
}
