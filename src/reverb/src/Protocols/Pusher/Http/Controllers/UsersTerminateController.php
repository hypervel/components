<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;

class UsersTerminateController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(Request $request, string $appId, string $userId): JsonResponse
    {
        $context = $this->verify($request, $appId);

        if (app(ServerProviderManager::class)->subscribesToEvents()) {
            app(PubSubProvider::class)->publish([
                'type' => 'terminate',
                'app_id' => $context->application->id(),
                'user_id' => $userId,
            ]);

            return new JsonResponse((object) []);
        }

        $connections = collect($context->channels->connections());

        $connections->each(function ($connection) use ($userId) {
            if ((string) ($connection->data()['user_id'] ?? '') === $userId) {
                $connection->disconnect();
            }
        });

        return new JsonResponse((object) []);
    }
}
