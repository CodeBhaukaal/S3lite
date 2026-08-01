<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Share;
use App\Services\ShareService;

final class ShareApiController extends Controller
{
    public function index(Request $request): Response
    {
        $result = Share::listForUser($this->userId(), $this->pageNumber($request), $this->perPage($request, 25));

        return $this->json(
            array_map([Share::class, 'publicArray'], $result['data']),
            200,
            $this->paginationMeta($result)
        );
    }

    public function show(Request $request, string $id): Response
    {
        return $this->json(Share::publicArray($this->resolve($id)));
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

        return $this->json(Share::publicArray(
            ShareService::update($this->userId(), (int) $share['id'], $options)
        ));
    }

    public function destroy(Request $request, string $id): Response
    {
        $share = $this->resolve($id);

        ShareService::revoke($this->userId(), (int) $share['id']);

        return $this->json(['revoked' => true]);
    }

    private function resolve(string $id): array
    {
        $share = ctype_digit($id) ? Share::find((int) $id) : Share::findBy('uuid', $id);

        if ($share === null) {
            $share = Share::findByToken($id);
        }

        if ($share === null || (int) $share['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Share link not found.', 'share_not_found');
        }

        return $share;
    }
}
