<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\UserConnectionTerminator;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\TerminateUserPipeMessage;
use RuntimeException;
use Swoole\Server;
use Throwable;

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

        $exception = null;

        try {
            app(UserConnectionTerminator::class)->terminate($context->application, $userId);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        $server = app(Server::class);
        $workerCount = (int) ($server->setting['worker_num'] ?? 1);
        $message = new TerminateUserPipeMessage($context->application->id(), $userId);

        for ($workerId = 0; $workerId < $workerCount; ++$workerId) {
            if ($workerId === $server->worker_id) {
                continue;
            }

            try {
                if (! $server->sendMessage($message, $workerId)) {
                    $exception ??= new RuntimeException(
                        "Unable to terminate Reverb user connections on worker [{$workerId}].",
                    );
                }
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return new JsonResponse((object) []);
    }
}
