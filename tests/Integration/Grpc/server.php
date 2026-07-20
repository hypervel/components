<?php

declare(strict_types=1);

use Hypervel\Engine\Coroutine;
use Hypervel\Grpc\GrpcServiceProvider;
use Hypervel\Grpc\Health\HealthStatusProvider;
use Hypervel\Server\ServerFactory;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Tests\Integration\Grpc\Fixtures\TestHealthStatusProvider;
use Swoole\Constant;

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

$port = (int) (env('GRPC_TEST_SERVER_PORT') ?: 19520);
$certificate = env('GRPC_TEST_SERVER_CERT');
$privateKey = env('GRPC_TEST_SERVER_KEY');
$compression = env('GRPC_TEST_SERVER_COMPRESSION');

$app = TestbenchApplication::create(
    resolvingCallback: function ($app) use (
        $port,
        $certificate,
        $privateKey,
        $compression,
    ): void {
        $config = $app->make('config');
        $config->set('server.servers', []);

        // Merge package defaults while the gRPC listener is still disabled, then
        // register the enabled provider after its test-only route path is known.
        (new GrpcServiceProvider($app))->register();
        $config->set('grpc.server', array_replace_recursive(
            $config->array('grpc.server'),
            [
                'enabled' => true,
                'name' => 'grpc-test',
                'host' => '127.0.0.1',
                'port' => $port,
                'routes' => __DIR__ . '/Fixtures/routes.php',
                'compression' => $compression,
                'tls' => [
                    'local_cert' => $certificate ?: null,
                    'local_pk' => $privateKey ?: null,
                ],
            ],
        ));
        $app->instance(HealthStatusProvider::class, new TestHealthStatusProvider);
        $app->register(GrpcServiceProvider::class);

        $config->set('server.mode', SWOOLE_BASE);
        $config->set('server.settings.' . Constant::OPTION_WORKER_NUM, 1);
    },
);

echo "Starting Hypervel gRPC test server on 127.0.0.1:{$port}...\n";

Coroutine::set(['hook_flags' => swoole_hook_flags()]);

$serverFactory = $app->make(ServerFactory::class)
    ->setEventDispatcher($app->make('events'))
    ->setLogger($app->make(Hypervel\Contracts\Log\StdoutLoggerInterface::class));

$serverFactory->configure($app->make('config')->array('server'));

echo "Hypervel gRPC test server running. Press Ctrl+C to stop.\n";

$serverFactory->start();
