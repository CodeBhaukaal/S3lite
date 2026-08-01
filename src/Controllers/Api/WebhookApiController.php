<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Webhook;
use App\Services\AuditService;
use App\Services\WebhookService;
use App\Support\Str;

final class WebhookApiController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json(array_map([Webhook::class, 'publicArray'], Webhook::forUser($this->userId())));
    }

    public function events(Request $request): Response
    {
        return $this->json(Webhook::EVENTS);
    }

    public function store(Request $request): Response
    {
        $this->validate($request, [
            'name'   => 'required|string|max:120',
            'url'    => 'required|url|max:500',
            'events' => 'required|array|min:1',
        ]);

        $events = array_values(array_intersect($request->array('events'), Webhook::EVENTS));

        if ($events === []) {
            return $this->error('invalid_events', 'None of the supplied events are supported.', 422, [
                'available' => Webhook::EVENTS,
            ]);
        }

        $secret = Str::random(48);

        $id = Webhook::create([
            'uuid'      => Str::uuid(),
            'user_id'   => $this->userId(),
            'name'      => $request->string('name'),
            'url'       => $request->string('url'),
            'events'    => $events,
            'secret'    => $secret,
            'is_active' => $request->bool('is_active', true) ? 1 : 0,
        ]);

        AuditService::log('webhook.create', 'webhook', $id, 'Created webhook via API', ['events' => $events]);

        return $this->json(
            Webhook::publicArray(Webhook::find($id) ?? []) + [
                'secret'  => $secret,
                'warning' => 'Store this secret — deliveries are signed with it (X-S3Lite-Signature).',
            ],
            201
        );
    }

    public function update(Request $request, string $id): Response
    {
        $hook = $this->resolve($id);
        $data = [];

        foreach (['name', 'url'] as $field) {
            if ($request->filled($field)) {
                $data[$field] = $request->string($field);
            }
        }

        if ($request->has('events')) {
            $events = array_values(array_intersect($request->array('events'), Webhook::EVENTS));

            if ($events === []) {
                return $this->error('invalid_events', 'None of the supplied events are supported.', 422);
            }

            $data['events'] = $events;
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->bool('is_active') ? 1 : 0;
            if ($data['is_active'] === 1) {
                $data['failures'] = 0;
            }
        }

        if ($data !== []) {
            Webhook::updateById((int) $hook['id'], $data);
        }

        return $this->json(Webhook::publicArray(Webhook::find((int) $hook['id']) ?? []));
    }

    public function destroy(Request $request, string $id): Response
    {
        $hook = $this->resolve($id);

        Webhook::deleteById((int) $hook['id']);
        AuditService::log('webhook.delete', 'webhook', (int) $hook['id'], 'Deleted webhook via API');

        return $this->json(['deleted' => true]);
    }

    public function test(Request $request, string $id): Response
    {
        $hook = $this->resolve($id);

        $result = WebhookService::deliver((int) $hook['id'], 'ping', [
            'message' => 'Test delivery from S3 Lite',
            'time'    => date('c'),
        ]);

        return $this->json($result, $result['ok'] ? 200 : 502);
    }

    public function deliveries(Request $request, string $id): Response
    {
        $hook = $this->resolve($id);

        return $this->json(WebhookService::deliveries((int) $hook['id'], $this->perPage($request, 50, 200)));
    }

    private function resolve(string $id): array
    {
        $hook = ctype_digit($id) ? Webhook::find((int) $id) : Webhook::findBy('uuid', $id);

        if ($hook === null || (int) $hook['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Webhook not found.', 'webhook_not_found');
        }

        return $hook;
    }
}
