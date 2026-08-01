<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\FileRecord;
use App\Models\Folder;
use App\Models\Share;
use App\Services\DownloadService;
use App\Services\ShareService;

final class ShareController extends Controller
{
    // --- Owner-facing ---------------------------------------------------

    public function index(Request $request): Response
    {
        return $this->view('app.shares', [
            'shares' => Share::listForUser($this->userId(), $this->pageNumber($request), $this->perPage($request, 20)),
        ]);
    }

    public function store(Request $request): Response
    {
        $userId = $this->userId();

        $options = [
            'type'          => $request->string('type', 'permanent'),
            'password'      => $request->string('password') ?: null,
            'expires_at'    => $request->string('expires_at') ?: null,
            'max_downloads' => $request->string('max_downloads') === '' ? null : $request->int('max_downloads'),
            'allow_preview' => $request->bool('allow_preview', true),
        ];

        $fileParam = $request->string('file_id');
        $folderParam = $request->string('folder_id');

        if ($fileParam !== '') {
            $file = FileRecord::ownedBy($fileParam, $userId);
            if ($file === null) {
                return $this->error('file_not_found', 'File not found.', 404);
            }
            $share = ShareService::createForFile($userId, (int) $file['id'], $options);
        } elseif ($folderParam !== '') {
            $folder = Folder::ownedBy($folderParam, $userId);
            if ($folder === null) {
                return $this->error('folder_not_found', 'Folder not found.', 404);
            }
            $share = ShareService::createForFolder($userId, (int) $folder['id'], $options);
        } else {
            return $this->error('missing_target', 'Provide either file_id or folder_id.', 422);
        }

        if ($request->wantsJson()) {
            return $this->json(Share::publicArray($share), 201);
        }

        return $this->back($request, 'success', 'Share link created: ' . url('s/' . $share['token']));
    }

    public function update(Request $request, string $id): Response
    {
        $share = $this->resolve($id);

        $options = [];
        foreach (['is_active', 'allow_preview'] as $flag) {
            if ($request->has($flag)) {
                $options[$flag] = $request->bool($flag);
            }
        }
        foreach (['max_downloads', 'expires_at', 'password'] as $field) {
            if ($request->has($field)) {
                $options[$field] = $request->input($field);
            }
        }

        $updated = ShareService::update($this->userId(), (int) $share['id'], $options);

        if ($request->wantsJson()) {
            return $this->json(Share::publicArray($updated));
        }

        return $this->back($request, 'success', 'Share link updated.');
    }

    public function destroy(Request $request, string $id): Response
    {
        $share = $this->resolve($id);

        ShareService::revoke($this->userId(), (int) $share['id']);

        if ($request->wantsJson()) {
            return $this->json(['revoked' => true]);
        }

        return $this->back($request, 'success', 'Share link revoked.');
    }

    // --- Public-facing --------------------------------------------------

    public function publicShow(Request $request, string $token): Response
    {
        $result = ShareService::resolve($token);

        if (!$result['ok']) {
            return $this->view('share.unavailable', ['message' => $result['error']], 404);
        }

        /** @var array $share */
        $share = $result['share'];

        if (ShareService::requiresPassword($share) && !$this->passwordAccepted($share)) {
            return $this->view('share.password', ['token' => $token, 'error' => null]);
        }

        $file = $result['file'];
        $folder = $result['folder'];

        $textPreview = null;
        if ($file !== null && (int) $share['allow_preview'] === 1
            && FileRecord::kind($file) === 'text' && (int) $file['size'] < 524288) {
            $textPreview = DownloadService::previewText($file);
        }

        return $this->view('share.show', [
            'share'       => $share,
            'file'        => $file,
            'folder'      => $folder,
            'files'       => $folder === null ? [] : FileRecord::search(
                ['user_id' => (int) $share['user_id'], 'folder_id' => (int) $folder['id']],
                $this->pageNumber($request),
                50
            ),
            'textPreview' => $textPreview,
        ]);
    }

    public function publicUnlock(Request $request, string $token): Response
    {
        $result = ShareService::resolve($token);

        if (!$result['ok']) {
            return $this->view('share.unavailable', ['message' => $result['error']], 404);
        }

        /** @var array $share */
        $share = $result['share'];

        if (!ShareService::verifyPassword($share, (string) $request->input('password', ''))) {
            return $this->view('share.password', [
                'token' => $token,
                'error' => 'That password is not correct.',
            ], 422);
        }

        Session::put('share_unlocked_' . $share['id'], time());

        return $this->redirect('/s/' . $token);
    }

    public function publicDownload(Request $request, string $token): Response
    {
        $result = ShareService::resolve($token);

        if (!$result['ok']) {
            return $this->view('share.unavailable', ['message' => $result['error']], 404);
        }

        /** @var array $share */
        $share = $result['share'];

        if (ShareService::requiresPassword($share) && !$this->passwordAccepted($share)) {
            return $this->view('share.password', ['token' => $token, 'error' => 'Enter the password to download this file.'], 403);
        }

        $file = $result['file'];

        if ($file === null) {
            $fileParam = $request->string('file');

            if ($fileParam === '' || $result['folder'] === null) {
                return $this->view('share.unavailable', ['message' => 'Nothing to download.'], 404);
            }

            $file = FileRecord::resolve($fileParam);

            if ($file === null
                || (int) $file['user_id'] !== (int) $share['user_id']
                || (int) $file['folder_id'] !== (int) $result['folder']['id']
                || $file['deleted_at'] !== null) {
                return $this->view('share.unavailable', ['message' => 'That file is not part of this share.'], 404);
            }
        }

        \App\Services\WebhookService::dispatch('share.accessed', [
            'share_id' => (int) $share['id'],
            'token'    => $token,
            'file_id'  => (int) $file['id'],
            'ip'       => $request->ip(),
        ], (int) $share['user_id']);

        return DownloadService::serve($request, $file, false, (int) $share['id'], 'share');
    }

    public function publicPreview(Request $request, string $token): Response
    {
        $result = ShareService::resolve($token);

        if (!$result['ok'] || $result['file'] === null) {
            throw new HttpException(404, 'Not available.', 'not_found');
        }

        /** @var array $share */
        $share = $result['share'];

        if ((int) $share['allow_preview'] !== 1) {
            throw new HttpException(403, 'Preview is disabled for this link.', 'preview_disabled');
        }

        if (ShareService::requiresPassword($share) && !$this->passwordAccepted($share)) {
            throw new HttpException(403, 'This link is password protected.', 'password_required');
        }

        return DownloadService::serve($request, $result['file'], true, (int) $share['id'], 'share');
    }

    private function passwordAccepted(array $share): bool
    {
        $unlockedAt = Session::get('share_unlocked_' . $share['id']);

        return is_int($unlockedAt) && (time() - $unlockedAt) < 3600;
    }

    private function resolve(string $id): array
    {
        $share = ctype_digit($id) ? Share::find((int) $id) : Share::findBy('uuid', $id);

        if ($share === null || (int) $share['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Share link not found.', 'share_not_found');
        }

        return $share;
    }
}
