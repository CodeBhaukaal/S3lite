<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth;
use App\Services\QuotaService;
use App\Services\StatsService;
use App\Services\UserService;

final class UserApiController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requireAdmin();

        $conditions = ['deleted_at' => null];

        if ($request->string('status') !== '') {
            $conditions['status'] = $request->string('status');
        }

        if ($request->string('q') !== '') {
            $term = '%' . $request->string('q') . '%';
            $conditions['__raw'] = ['u.name LIKE ? OR u.email LIKE ?', [$term, $term]];
        }

        $result = User::listWithRoles($conditions, $this->pageNumber($request), $this->perPage($request, 25));

        return $this->json(
            array_map([User::class, 'publicArray'], $result['data']),
            200,
            $this->paginationMeta($result)
        );
    }

    public function show(Request $request, string $id): Response
    {
        $user = $this->resolve($id);

        if (!Auth::isAdmin() && (int) $user['id'] !== $this->userId()) {
            throw new HttpException(403, 'You may only read your own account.', 'forbidden');
        }

        return $this->json([
            'user'  => User::publicArray($user),
            'stats' => StatsService::forUser((int) $user['id']),
            'permissions' => $user['permissions'] ?? [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireAdmin();

        $this->validate($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190|unique:users,email',
            'password' => 'required|password',
        ]);

        $user = UserService::create([
            'name'        => $request->string('name'),
            'email'       => $request->string('email'),
            'password'    => (string) $request->input('password', ''),
            'role'        => $request->string('role', 'user'),
            'status'      => $request->string('status', 'active'),
            'quota_bytes' => $request->has('quota_bytes') ? $request->int('quota_bytes') : null,
        ]);

        return $this->json(User::publicArray($user), 201);
    }

    public function update(Request $request, string $id): Response
    {
        $user = $this->resolve($id);
        $isAdmin = Auth::isAdmin();

        if (!$isAdmin && (int) $user['id'] !== $this->userId()) {
            throw new HttpException(403, 'You may only update your own account.', 'forbidden');
        }

        $data = [];
        foreach (['name', 'email'] as $field) {
            if ($request->filled($field)) {
                $data[$field] = $request->string($field);
            }
        }

        if ($request->filled('password')) {
            $data['password'] = (string) $request->input('password', '');
        }

        if ($isAdmin) {
            foreach (['role', 'status'] as $field) {
                if ($request->filled($field)) {
                    $data[$field] = $request->string($field);
                }
            }

            if ($request->has('quota_bytes')) {
                $data['quota_bytes'] = $request->int('quota_bytes');
            }

            if ($request->bool('unlock')) {
                $data['unlock'] = true;
            }
        }

        return $this->json(User::publicArray(UserService::update((int) $user['id'], $data, $isAdmin)));
    }

    public function suspend(Request $request, string $id): Response
    {
        $this->requireAdmin();

        return $this->json(User::publicArray(UserService::suspend((int) $this->resolve($id)['id'])));
    }

    public function activate(Request $request, string $id): Response
    {
        $this->requireAdmin();

        return $this->json(User::publicArray(UserService::activate((int) $this->resolve($id)['id'])));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->requireAdmin();

        $user = $this->resolve($id);

        if ((int) $user['id'] === $this->userId()) {
            return $this->error('self_delete', 'You cannot delete your own account.', 422);
        }

        UserService::delete((int) $user['id'], $request->bool('purge_files'));

        return $this->json(['deleted' => true, 'purged' => $request->bool('purge_files')]);
    }

    public function quota(Request $request, string $id): Response
    {
        $user = $this->resolve($id);

        if (!Auth::isAdmin() && (int) $user['id'] !== $this->userId()) {
            throw new HttpException(403, 'You may only read your own quota.', 'forbidden');
        }

        if ($request->method === 'GET') {
            return $this->json(QuotaService::check((int) $user['id']));
        }

        $this->requireAdmin();
        $this->validate($request, ['quota_bytes' => 'required|int|min:0']);

        UserService::update((int) $user['id'], ['quota_bytes' => $request->int('quota_bytes')], true);

        return $this->json(QuotaService::check((int) $user['id']));
    }

    public function recalculate(Request $request, string $id): Response
    {
        $this->requireAdmin();

        $user = $this->resolve($id);

        return $this->json(['used_bytes' => QuotaService::recalculate((int) $user['id'])]);
    }

    public function roles(Request $request): Response
    {
        $this->requireAdmin();

        $roles = Role::withCounts();

        foreach ($roles as $i => $role) {
            $roles[$i]['permissions'] = Role::permissionNames((int) $role['id']);
        }

        return $this->json($roles);
    }

    private function resolve(string $id): array
    {
        $user = null;

        if (ctype_digit($id)) {
            $user = User::withRole((int) $id);
        } else {
            $found = User::findByUuid($id);
            $user = $found === null ? null : User::withRole((int) $found['id']);
        }

        if ($user === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }

        return $user;
    }
}
