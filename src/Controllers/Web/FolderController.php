<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Folder;
use App\Services\FolderService;

final class FolderController extends Controller
{
    public function store(Request $request): Response
    {
        $this->validate($request, ['name' => 'required|string|max:120']);

        $parentId = null;
        $parentParam = $request->string('parent_id');

        if ($parentParam !== '' && $parentParam !== 'root' && $parentParam !== '0') {
            $parent = Folder::ownedBy($parentParam, $this->userId());
            if ($parent === null) {
                return $this->error('folder_not_found', 'Parent folder not found.', 404);
            }
            $parentId = (int) $parent['id'];
        }

        $folder = FolderService::create(
            $this->userId(),
            $request->string('name'),
            $parentId,
            $request->string('color') ?: null
        );

        if ($request->wantsJson()) {
            return $this->json(Folder::publicArray($folder), 201);
        }

        return $this->back($request, 'success', 'Folder created.');
    }

    public function rename(Request $request, string $id): Response
    {
        $folder = $this->resolve($id);
        $this->validate($request, ['name' => 'required|string|max:120']);

        FolderService::rename($this->userId(), (int) $folder['id'], $request->string('name'));

        return $this->respond($request, 'Folder renamed.');
    }

    public function move(Request $request, string $id): Response
    {
        $folder = $this->resolve($id);
        $parentParam = $request->string('parent_id');

        $parentId = null;
        if ($parentParam !== '' && $parentParam !== 'root' && $parentParam !== '0') {
            $parent = Folder::ownedBy($parentParam, $this->userId());
            if ($parent === null) {
                return $this->error('folder_not_found', 'Destination folder not found.', 404);
            }
            $parentId = (int) $parent['id'];
        }

        FolderService::move($this->userId(), (int) $folder['id'], $parentId);

        return $this->respond($request, 'Folder moved.');
    }

    public function trash(Request $request, string $id): Response
    {
        $folder = $this->resolve($id);

        $result = FolderService::trash($this->userId(), (int) $folder['id']);

        if ($request->wantsJson()) {
            return $this->json($result);
        }

        $parent = $folder['parent_id'] === null ? '' : '?folder=' . $folder['parent_id'];

        return $this->redirect('/files' . $parent, 'success', "Folder moved to trash ({$result['files']} files).");
    }

    public function tree(Request $request): Response
    {
        return $this->json(FolderService::tree($this->userId()));
    }

    private function resolve(string $id): array
    {
        $folder = Folder::ownedBy($id, $this->userId());

        if ($folder === null) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        return $folder;
    }

    private function respond(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return $this->json(['message' => $message]);
        }

        return $this->back($request, 'success', $message);
    }
}
