<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Database;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\FileRecord;
use App\Models\Folder;
use App\Services\FileService;
use App\Services\FolderService;
use App\Services\SettingService;

final class TrashController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->userId();

        $files = FileRecord::search(
            ['user_id' => $userId, 'trashed' => true, 'q' => $request->string('q')],
            $this->pageNumber($request),
            $this->perPage($request, 30),
            'deleted_at',
            'desc'
        );

        $folders = Database::select(
            'SELECT * FROM folders WHERE user_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 200',
            [$userId]
        );

        return $this->view('app.trash', [
            'files'     => $files,
            'folders'   => $folders,
            'retention' => (int) SettingService::get('trash_retention_days', Config::get('storage.trash_retention_days', 30)),
            'query'     => $request->string('q'),
        ]);
    }

    public function restoreFile(Request $request, string $id): Response
    {
        $file = FileRecord::ownedBy($id, $this->userId(), true);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        FileService::restore($this->userId(), (int) $file['id']);

        return $this->respond($request, 'File restored.');
    }

    public function purgeFile(Request $request, string $id): Response
    {
        $file = FileRecord::ownedBy($id, $this->userId(), true);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        FileService::purge($this->userId(), (int) $file['id']);

        return $this->respond($request, 'File permanently deleted.');
    }

    public function restoreFolder(Request $request, string $id): Response
    {
        $folder = Folder::resolve($id);

        if ($folder === null || (int) $folder['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        $result = FolderService::restore($this->userId(), (int) $folder['id']);

        return $this->respond($request, "Folder restored ({$result['files']} files).");
    }

    public function purgeFolder(Request $request, string $id): Response
    {
        $folder = Folder::resolve($id);

        if ($folder === null || (int) $folder['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        $result = FolderService::purge($this->userId(), (int) $folder['id']);

        return $this->respond($request, "Folder permanently deleted ({$result['files']} files).");
    }

    public function empty(Request $request): Response
    {
        $count = FileService::emptyTrash($this->userId());

        return $this->respond($request, "{$count} item(s) permanently deleted.");
    }

    private function respond(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return $this->json(['message' => $message]);
        }

        return $this->back($request, 'success', $message);
    }
}
