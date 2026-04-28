<?php

declare(strict_types=1);

/**
 * Reverb integration test server.
 *
 * Boots a Hypervel application with Reverb enabled on a configurable port.
 * Handles both WebSocket connections and HTTP API requests on the same port.
 *
 * Configuration via environment variables:
 *   REVERB_SERVER_PORT        — Port to listen on (default: 19510)
 *   REVERB_SCALING_ENABLED    — Enable Redis scaling (default: false)
 *   REVERB_TEST_WORKER_NUM    — Number of Swoole workers (default: 1, uses SWOOLE_PROCESS when > 1)
 *
 * Usage:
 *   php tests/Integration/Reverb/server.php
 *   REVERB_SERVER_PORT=19511 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 *   REVERB_SERVER_PORT=19512 REVERB_TEST_WORKER_NUM=2 php tests/Integration/Reverb/server.php
 *
 * Stop with Ctrl+C.
 */

use Hypervel\Engine\Coroutine;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Server\ServerFactory;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Tests\Integration\Reverb\ParallelTestApplicationProvider;

use function Hypervel\Support\swoole_hook_flags;

require_once __DIR__ . '/../../../vendor/autoload.php';

// Load .env file — server scripts run outside PHPUnit so the test bootstrap
// (which normally loads .env) doesn't run. Without this, env vars like
// REDIS_PASSWORD are missing and Redis auth fails.
$dotenvPath = dirname(__DIR__, 3);
if (file_exists($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable($dotenvPath)->load();
}

// Set up BASE_PATH, SWOOLE_HOOK_FLAGS, etc.
Bootstrapper::bootstrap();

// The app must not think it's running in console — ServerStartCommand
// refuses to start and service providers gate console-only registrations.
putenv('APP_RUNNING_IN_CONSOLE=false');
$_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
$_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

// Read port, scaling flag, and worker count from environment.
$port = (string) (env('REVERB_SERVER_PORT') ?: '19510');
$scaling = env('REVERB_SCALING_ENABLED') === true;
$workerNum = (int) (env('REVERB_TEST_WORKER_NUM') ?: 1);
$label = $scaling ? 'Reverb Redis test server' : ($workerNum > 1 ? "Reverb multi-worker test server ({$workerNum} workers)" : 'Reverb test server');

// Set Reverb app env vars BEFORE boot so the config file picks them up.
// These survive the worker-start config reload (ReloadDotenvAndConfig)
// because the reload re-reads env vars and rebuilds config from disk.
// Only set defaults — don't override values already set by the caller.
$defaults = [
    'REVERB_APP_KEY' => 'reverb-key',
    'REVERB_APP_SECRET' => 'reverb-secret',
    'REVERB_APP_ID' => '123456',
    'REVERB_HOST' => 'localhost',
    'REVERB_PORT' => $port,
    'REVERB_SCHEME' => 'http',
    'REVERB_SERVER_HOST' => '0.0.0.0',
    'REVERB_SERVER_PORT' => $port,
    'REVERB_APP_ACTIVITY_TIMEOUT' => '30',
    'REVERB_APP_MAX_MESSAGE_SIZE' => '10000',
    'REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM' => 'members',
];

foreach ($defaults as $key => $value) {
    if (env($key) === null) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Boot a fully bootstrapped Hypervel app with Reverb enabled.
$app = TestbenchApplication::create(
    resolvingCallback: function ($app) use ($workerNum) {
        // Resolve ReloadDotenvAndConfig to register the config tracking callback
        // BEFORE any config()->set() calls. This ensures runtime config mutations
        // (like the server config below) are tracked and replayed when the worker
        // starts and rebuilds config from disk.
        $app->make(ReloadDotenvAndConfig::class);

        // Clear the default HTTP server entry — the test server only needs the
        // Reverb WebSocket server. Must happen before the provider registers so
        // registerWebSocketServer() appends to an empty array.
        $app->make('config')->set('server.servers', []);

        // Register Reverb provider (register + boot fires immediately since app is booted).
        // registerWebSocketServer() appends the Reverb server entry using the port
        // from REVERB_SERVER_PORT (set above via env vars → config).
        $app->register(ReverbServiceProvider::class);

        // Webhook inspection only works with worker_num=1 (no fork).
        // Queue::fake() creates an in-memory fake that doesn't survive forking.
        if ($workerNum === 1) {
            $app->make('config')->set('reverb.apps.apps.0.webhooks', [
                'url' => 'https://example.com/webhook',
                'events' => ['channel_occupied', 'channel_vacated', 'member_added', 'member_removed', 'client_event'],
                'disconnect_smoothing_ms' => 0,
            ]);

            Queue::fake([WebhookDeliveryJob::class]);
        }

        // Add additional test apps (env vars only support one app).
        // These mutations are tracked by ReloadDotenvAndConfig and survive worker restart.
        $app->make('config')->set('reverb.apps.apps.1', [
            'key' => 'reverb-key-2',
            'secret' => 'reverb-secret-2',
            'app_id' => '654321',
            'allowed_origins' => ['*'],
            'ping_interval' => 10,
            'activity_timeout' => 30,
            'max_message_size' => 1_000_000,
            'max_connections' => 1,
        ]);

        $app->make('config')->set('reverb.apps.apps.2', [
            'key' => 'reverb-key-3',
            'secret' => 'reverb-secret-3',
            'app_id' => '987654',
            'allowed_origins' => ['laravel.com'],
            'ping_interval' => 10,
            'activity_timeout' => 30,
            'max_message_size' => 1,
        ]);

        // Wrap the ApplicationProvider with a dynamic resolver for parallel test
        // isolation. Each paratest worker derives unique app credentials from
        // TEST_TOKEN — the wrapper recognizes the pattern and creates apps on
        // demand without pre-registering a fixed list.
        $app->instance(
            ApplicationProvider::class,
            new ParallelTestApplicationProvider(
                $app->make(ApplicationProvider::class),
            ),
        );

        // Test-only route: age connections for a specific app and ping inactive ones.
        // Scoped by app ID so parallel workers don't interfere. Runs the ping
        // logic directly for this app rather than delegating to the job (which
        // iterates all() apps and wouldn't find dynamically-resolved parallel apps).
        $app->make(ReverbRouter::class)->post('/_test/ping-inactive/{appId}', function (\Hypervel\Http\Request $request, string $appId) use ($app) {
            $channelManager = $app->make(\Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager::class);
            $application = $app->make(ApplicationProvider::class)->findById($appId);
            $pusher = new \Hypervel\Reverb\Protocols\Pusher\EventHandler($channelManager);

            foreach ($channelManager->for($application)->connections() as $connection) {
                $connection->connection()->setLastSeenAt(time() - 600);
            }

            foreach ($channelManager->for($application)->connections() as $connection) {
                if (! $connection->isActive()) {
                    $pusher->ping($connection->connection());
                }
            }

            return new \Hypervel\Http\JsonResponse(['ok' => true]);
        });

        // Test-only route: prune stale connections for a specific app.
        // Mirrors PruneStaleConnections — only disconnect, don't unsubscribeFromAll.
        // The onClose → Server::close() path handles channel cleanup and slot release.
        $app->make(ReverbRouter::class)->post('/_test/prune-stale/{appId}', function (\Hypervel\Http\Request $request, string $appId) use ($app) {
            $channelManager = $app->make(\Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager::class);
            $application = $app->make(ApplicationProvider::class)->findById($appId);

            foreach ($channelManager->for($application)->connections() as $connection) {
                if ($connection->connection()->isStale()) {
                    $connection->connection()->send(json_encode([
                        'event' => 'pusher:error',
                        'data' => json_encode([
                            'code' => 4201,
                            'message' => 'Pong reply not received in time',
                        ]),
                    ]));

                    $connection->connection()->disconnect();
                }
            }

            return new \Hypervel\Http\JsonResponse(['ok' => true]);
        });

        // Webhook test routes — only available on single-worker servers.
        if ($workerNum === 1) {
            $app->make(ReverbRouter::class)->post('/_test/queue-reset', function () {
                Queue::fake([WebhookDeliveryJob::class]);

                return new \Hypervel\Http\JsonResponse(['ok' => true]);
            });

            $app->make(ReverbRouter::class)->get('/_test/queued-jobs', function () {
                /** @var \Hypervel\Support\Testing\Fakes\QueueFake $fake */
                $fake = Queue::getFacadeRoot();

                $jobs = $fake->pushed(WebhookDeliveryJob::class)->map(function (WebhookDeliveryJob $job) {
                    $event = $job->payload->events[0] ?? [];

                    return [
                        'event' => $event['name'] ?? null,
                        'channel' => $event['channel'] ?? null,
                        'url' => $job->url,
                        'appKey' => $job->appKey,
                        'webhookId' => $job->payload->webhookId,
                    ];
                })->values()->all();

                return new \Hypervel\Http\JsonResponse(['jobs' => $jobs]);
            });
        }

        // Test-only: shared-state endpoint for Swoole Table counter assertions.
        // Reads directly from shared memory — visible from any worker.
        $app->make(ReverbRouter::class)->get('/_test/shared-state/{key}', function (\Hypervel\Http\Request $request, string $key) use ($app) {
            $sharedState = $app->make(\Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState::class);

            if (! $sharedState instanceof \Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState) {
                return new \Hypervel\Http\JsonResponse(['error' => 'Not using Swoole Table'], 400);
            }

            $value = $sharedState->table()->get($key, 'count');

            return new \Hypervel\Http\JsonResponse(['key' => $key, 'count' => $value !== false ? $value : null]);
        });

        // Test-only: drain all connections on this worker using the production drain method.
        $app->make(ReverbRouter::class)->post('/_test/drain-connections', function () use ($app) {
            $app->getProvider(ReverbServiceProvider::class)->drainConnections();

            return new \Hypervel\Http\JsonResponse(['ok' => true]);
        });

        // Override Swoole settings for test determinism.
        $config = $app->make('config');
        if ($workerNum > 1) {
            $config->set('server.mode', SWOOLE_PROCESS);
        } else {
            $config->set('server.mode', SWOOLE_BASE);
        }
        $config->set('server.settings.' . \Swoole\Constant::OPTION_WORKER_NUM, $workerNum);
        // Disable HTTP compression so Content-Length headers reflect the raw body
        // size, allowing integration tests to assert exact Content-Length values.
        $config->set('server.settings.' . \Swoole\Constant::OPTION_HTTP_COMPRESSION, false);

        // Test-only: extend WebSocketHandler to intercept pusher:test_worker_id.
        // Responds with the worker ID of the worker that owns the connection.
        // Used by multi-worker tests to verify cross-worker distribution.
        $app->singleton(\Hypervel\Reverb\Servers\Hypervel\WebSocketHandler::class, function ($app) {
            return new class($app->make(\Hypervel\Contracts\Container\Container::class), $app->make(\Hypervel\Reverb\Protocols\Pusher\Server::class), $app->make(ApplicationProvider::class)) extends \Hypervel\Reverb\Servers\Hypervel\WebSocketHandler {
                public function onMessage(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame): void
                {
                    if ($frame->opcode === WEBSOCKET_OPCODE_TEXT) {
                        $data = json_decode($frame->data, true);

                        if (($data['event'] ?? null) === 'pusher:test_worker_id') {
                            $server->push($frame->fd, json_encode([
                                'event' => 'pusher:test_worker_id',
                                'data' => json_encode(['worker_id' => $server->worker_id]),
                            ]));

                            return;
                        }
                    }

                    parent::onMessage($server, $frame);
                }
            };
        });
    },
);

$port = env('REVERB_SERVER_PORT', 19510);

echo "Starting {$label} on 0.0.0.0:{$port}...\n";

Coroutine::set(['hook_flags' => swoole_hook_flags()]);

$serverFactory = $app->make(ServerFactory::class)
    ->setEventDispatcher($app->make('events'))
    ->setLogger($app->make(\Hypervel\Contracts\Log\StdoutLoggerInterface::class));

$serverFactory->configure($app->make('config')->get('server'));

// Bind the Swoole server instance so Reverb's EventDispatcher can resolve it
$app->instance(\Swoole\Server::class, $serverFactory->getServer()->getServer());

echo "{$label} running. Press Ctrl+C to stop.\n";

$serverFactory->start();
