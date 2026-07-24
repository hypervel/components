<?php

declare(strict_types=1);

use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response as HypervelResponse;
use Hypervel\Http\UploadedFile;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\HttpServer\Server as HttpServer;
use Hypervel\Routing\Router;
use Hypervel\Server\Event;
use Hypervel\Server\Server;
use Hypervel\Server\ServerFactory;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Swoole\Constant;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

use function Hypervel\Support\swoole_hook_flags;

require_once __DIR__ . '/../../../vendor/autoload.php';

$dotenvPath = dirname(__DIR__, 3);
if (file_exists($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable($dotenvPath)->load();
}

Bootstrapper::bootstrap();

putenv('APP_RUNNING_IN_CONSOLE=false');
$_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
$_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

$app = TestbenchApplication::create(
    resolvingCallback: function ($app): void {
        $config = $app->make('config');
        $config->set('server.mode', SWOOLE_BASE);
        $config->set('server.servers', [[
            'name' => 'http-integration',
            'type' => Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => 19506,
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [HttpServer::class, 'onRequest'],
            ],
        ]]);
        $config->set('server.settings.' . Constant::OPTION_WORKER_NUM, 1);
        $config->set('server.settings.' . Constant::OPTION_OPEN_HTTP2_PROTOCOL, true);
        $config->set('server.settings.' . Constant::OPTION_ENABLE_STATIC_HANDLER, false);
        $config->set('server.settings.' . Constant::OPTION_HTTP_COMPRESSION, false);

        $app->make(KernelContract::class)->pushMiddleware(HttpServerIntegrationMiddleware::class);

        $state = new HttpServerIntegrationState;
        $app->instance(HttpServerIntegrationState::class, $state);

        $events = $app->make('events');

        // The state read relies on the target's non-yielding termination path
        // completing its deferred event before the next request is processed.
        $events->listen(RequestReceived::class, function (RequestReceived $event) use ($state): void {
            $token = $event->request?->query('token');

            if ($event->request?->getPathInfo() !== '/lifecycle-state'
                || ! is_string($token)
                || $token === ''
            ) {
                return;
            }

            $state->lifecycles[$token]['received'] = [
                'path' => $event->request?->getPathInfo(),
                'response_is_null' => $event->response === null,
            ];
        });
        $events->listen(RequestHandled::class, function (RequestHandled $event) use ($state): void {
            $path = $event->request?->getPathInfo();
            $token = $event->request?->query('token');

            if (! in_array($path, ['/lifecycle-target', '/failure'], true)
                || ! is_string($token)
                || $token === ''
            ) {
                return;
            }

            $state->lifecycles[$token]['handled'] = [
                'path' => $event->request?->getPathInfo(),
                'status' => $event->response?->getStatusCode(),
                'exception' => $event->exception?->getMessage(),
                'response_exception' => $event->response instanceof HypervelResponse
                    ? $event->response->exception?->getMessage()
                    : null,
            ];
        });
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use ($state): void {
            $path = $event->request?->getPathInfo();
            $token = $event->request?->query('token');

            if (! in_array($path, ['/lifecycle-target', '/failure'], true)
                || ! is_string($token)
                || $token === ''
            ) {
                return;
            }

            $state->lifecycles[$token]['terminated'] = [
                'path' => $event->request?->getPathInfo(),
                'status' => $event->response?->getStatusCode(),
                'exception' => $event->exception?->getMessage(),
                'response_exception' => $event->response instanceof HypervelResponse
                    ? $event->response->exception?->getMessage()
                    : null,
            ];
        });

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists(storage_path('app/private'));
        $filesystem->ensureDirectoryExists(storage_path('framework/testing'));

        $binaryPath = storage_path('framework/testing/http-server-binary.txt');
        $filesystem->put($binaryPath, '0123456789abcdefghijklmnopqrstuvwxyz');
        Storage::disk('local')->put('http-server-storage.txt', 'storage response body');

        $router = $app->make(Router::class);
        $router->get('/up', fn (): string => 'up');
        $router->get('/', fn (Request $request): string => $request->getRequestUri());
        $router->match(['GET', 'POST'], '/inspect', function (Request $request): JsonResponse {
            $file = $request->file('upload');

            return new JsonResponse([
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'query' => $request->query->all(),
                'request' => $request->request->all(),
                'json' => $request->json()->all(),
                'header' => $request->header('X-Integration'),
                'file' => $file instanceof UploadedFile ? [
                    'name' => $file->getClientOriginalName(),
                    'path' => $file->getClientOriginalPath(),
                    'type' => $file->getClientMimeType(),
                    'contents' => $file->getContent(),
                ] : null,
            ]);
        });
        $router->get('/context', function (Request $request): string {
            usleep(20_000);

            return (string) $request->query('value');
        });
        $router->get('/cookies', function (): Response {
            $response = new Response('cookies');
            $response->headers->setCookie(
                Cookie::create('raw', 'a%2Fb')->withRaw()->withPartitioned()
            );

            return $response;
        });
        $router->get('/callback-stream', fn (): Response => response()->stream(function (): void {
            echo 'callback-';
            echo 'stream';
        }));
        $router->get('/iterable-stream', fn (): Response => response()->stream(function (): iterable {
            yield 'iterable-';
            yield 'stream';
        }));
        $router->get('/storage-stream', fn (): Response => Storage::disk('local')->response('http-server-storage.txt'));
        $router->get('/binary', fn (): Response => response()->file($binaryPath));
        $router->get('/temporary-binary', function (): Response {
            $file = new SplTempFileObject;
            $file->fwrite('temporary binary body');
            $file->rewind();

            return response()->file($file);
        });
        $router->get('/delete-binary', function () use ($filesystem, $state): Response {
            $state->deletePath = storage_path('framework/testing/delete-' . bin2hex(random_bytes(8)) . '.txt');
            $filesystem->put($state->deletePath, 'delete after send');

            return response()->file($state->deletePath)->deleteFileAfterSend();
        });
        $router->get('/delete-state', fn (): JsonResponse => new JsonResponse([
            'exists' => $state->deletePath !== null && file_exists($state->deletePath),
        ]));
        $router->get('/disconnect-stream', function () use ($state): Response {
            $state->streamReleased = false;

            return response()->stream(function () use ($state): iterable {
                try {
                    for ($chunk = 0; $chunk < 100; ++$chunk) {
                        yield str_repeat((string) ($chunk % 10), 65_536);
                        usleep(10_000);
                    }
                } finally {
                    $state->streamReleased = true;
                }
            });
        });
        $router->get('/stream-state', fn (): JsonResponse => new JsonResponse([
            'released' => $state->streamReleased,
        ]));
        $router->get('/lifecycle-target', fn (): Response => new Response('lifecycle'));
        $router->get('/lifecycle-state', function (Request $request) use ($state): JsonResponse {
            $token = $request->query('token');

            if (! is_string($token) || $token === '') {
                return new JsonResponse([]);
            }

            $lifecycle = $state->lifecycles[$token] ?? [];
            unset($state->lifecycles[$token]);

            return new JsonResponse($lifecycle);
        });
        $router->get('/failure', fn () => throw new RuntimeException('integration failure'));
    },
);

Coroutine::set(['hook_flags' => swoole_hook_flags()]);

$serverFactory = $app->make(ServerFactory::class)
    ->setEventDispatcher($app->make('events'))
    ->setLogger($app->make(StdoutLoggerInterface::class));

$serverFactory->configure($app->make('config')->array('server'));
$serverFactory->start();

class HttpServerIntegrationMiddleware
{
    /**
     * Add a response header after the downstream handler completes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-After-Middleware', 'true');

        return $response;
    }
}

class HttpServerIntegrationState
{
    public bool $streamReleased = false;

    public ?string $deletePath = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $lifecycles = [];
}
