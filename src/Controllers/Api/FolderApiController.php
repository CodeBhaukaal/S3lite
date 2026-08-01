<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\FileRecord;
use App\Models\Folder;
use App\Services\FolderService;
use App\Services\ShareService;

final class FolderApiController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->userId();

        $parentId = null;
        if ($request->has('parent_id')) {
            $param = $request->string('parent_id');

            if ($param !== '' && $param !== 'root') {
                $parent = Folder::ownedBy($param, $userId);
                if ($parent === null) {
                    return $this->error('folder_not_found', 'Folder not found.', 404);
                }
                $parentId = (int) $parent['id'];
            }
        }

        if ($request->bool('tree')) {
            return $this->json(FolderService::tree($userId));
        }

        return $this->json(array_map([Folder::class, 'publicArray'], Folder::children($userId, $parentId)));
    }

    public function show(Request $request, string $id): Response
    {
        $folder = $this->resolve($id);

        $files = FileRecord::search(
            ['user_id' => $this->userId(), 'folder_id' => (int) $folder['id']],
            $this->pageNumber($request),
            $this->perPage($request, 25)
        );

        return $this->json([
            'folder'      => Folder::publicArray($folder),
            'breadcrumbs' => Folder::breadcrumbs((int) $folder['id']),
            'folders'     => array_map([Folder::class, 'publicArray'], Folder::children($this->userId(), (int) $folder['id'])),
            'files'       => array_map(static fn (array $f): array => FileRecord::publicArray($f), $files['data']),
            'size'        => Folder::size((int) $folder['id']),
        ], 200, $this->paginationMeta($files));
    }

    public function store(Request $request): Response
    {
        $this->validate($request, ['name' => 'required|string|max:120']);

        $parentId = null;
        $param = $request->string('parent_id');

        if ($param !== '' && $param !== 'root' && $param !== '0') {
            $parent = Folder::ownedBy($param, $this->userId());
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

        return $this->json(Folder::publicArray($folder), 201);
    }

    public function update(Request $request, string $id): Response
    {
        $folder = $this->resolve($id);
        $userId = $this->userId();

        if ($request->filled('name')) {
            $folder = FolderService::rename($userId, (int) $folder['id'], $request->string('name'));
        }

        if ($request->has('parent_id')) {
            $param = $request->string('parent_id');
            $parentId = null;

            if ($param !== '' && $param !== 'root' && $param !== '0') {
                $parent = Folder::ownedBy($param, $userId);
                if ($parent === null) {
                    return $this->error('folder_not_found', 'Destination folder not found.', 404);
                }
                $parentId = (int) $parent['id'];
            }

            $folder = FolderService::move($userId, (int) $folder['id'], $parentId);
        }

        if ($request->has('color')) {
            Folder::updateById((int) $folder['id'], ['color' => $request->string('color') ?: null]);
            $folder = Folder::find((int) $folder['id']) ?? $folder;
        }

        return $this->json(Folder::publicArray($folder));
    }

    public function destroy(Request $request, string $id): Response
    {
        $folder = Folder::resolve($id);

        if ($folder === null || (int) $folder['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        $permanent = $request->bool('permanent');

        $result = $permanent
            ? FolderService::purge($this->userId(), (int) $folder['id'])
            : FolderService::trash($this->userId(), (int) $folder['id']);

        return $this->json($result + ['permanent' => $permanent]);
    }

    public function restore(Request $request, string $id): Response
    {
        $folder = Folder::resolve($id);

        if ($folder === null || (int) $folder['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        return $this->json(FolderService::restore($this->userId(), (int) $folder['id']));
    }

    public function share(Request $request, string $id): Response
    {
        $folder = $this->resolve($id);

        $share = ShareService::createForFolder($this->userId(), (int) $folder['id'], [
            'type'          => $request->string('type', 'permanent'),
            'password'      => $request->string('password') ?: null,
            'expires_at'    => $request->string('expires_at') ?: null,
            'expires_in'    => $request->has('expires_in') ? $request->int('expires_in') : null,
            'allow_preview' => $request->bool('allow_preview', true),
        ]);

        return $this->json(\App\Models\Share::publicArray($share), 201);
    }

    private function resolve(string $id): array
    {
        $folder = Folder::ownedBy($id, $this->userId());

        if ($folder === null) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        return $folder;
    }
}
