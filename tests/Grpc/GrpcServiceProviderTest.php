<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Grpc\GrpcServiceProvider;
use Hypervel\Grpc\Health\HealthStatusProvider;
use Hypervel\Grpc\Health\ServingHealthStatusProvider;
use Hypervel\Grpc\Health\ServingStatus;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Server\CallContextStore;
use Hypervel\Grpc\Server\GrpcRouter;
use Hypervel\Grpc\Server\GrpcRouteRegistrar;
use Hypervel\Grpc\Server\ResponseFactory;
use Hypervel\Grpc\Server\Server;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Server\Event;
use Hypervel\Server\Exceptions\InvalidArgumentException as ServerInvalidArgumentException;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerInterface;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class GrpcServiceProviderTest extends TestCase
{
    private const ROUTES = __DIR__ . '/../../src/grpc/stubs/grpc.php';

    public function testClientOnlyConfigurationDoesNotAppendAListener(): void
    {
        $config = $this->app->make('config');
        $servers = $config->array('server.servers');

        (new GrpcServiceProvider($this->app))->register();

        $this->assertSame($servers, $config->array('server.servers'));
        $this->assertFalse($this->app->bound(GrpcRouter::class));
    }

    public function testRegistersTheDedicatedPortAndWorkerLifetimeServices(): void
    {
        $config = $this->app->make('config');
        $config->set('server.servers', [[
            'name' => 'http',
            'type' => ServerInterface::SERVER_HTTP,
            'host' => '127.0.0.1',
            'port' => 9501,
            'callbacks' => [],
        ]]);
        $this->registerEnabledProvider([
            'name' => 'grpc-api',
            'host' => '127.0.0.1',
            'port' => 55051,
            'compression' => 'gzip',
            'settings' => ['socket_buffer_size' => 65536],
        ]);

        $servers = $config->array('server.servers');
        $this->assertCount(2, $servers);
        $server = $servers[1];
        $this->assertSame('grpc-api', $server['name']);
        $this->assertSame(ServerInterface::SERVER_HTTP, $server['type']);
        $this->assertSame('127.0.0.1', $server['host']);
        $this->assertSame(55051, $server['port']);
        $this->assertSame(SWOOLE_SOCK_TCP, $server['sock_type']);
        $this->assertSame([Server::class, 'onRequest'], $server['callbacks'][Event::ON_REQUEST]);
        $this->assertSame(65536, $server['settings']['socket_buffer_size']);
        $this->assertTrue($server['settings']['open_http_protocol']);
        $this->assertTrue($server['settings']['open_http2_protocol']);
        $this->assertFalse($server['settings']['open_websocket_protocol']);
        $this->assertFalse($server['settings']['http_compression']);
        $this->assertSame(4 * 1024 * 1024 + 5, $server['settings']['package_max_length']);

        $this->assertInstanceOf(
            ServingHealthStatusProvider::class,
            $this->app->make(HealthStatusProvider::class),
        );
        $this->assertSame(
            $this->app->make(GrpcRouter::class),
            $this->app->make(GrpcRouter::class),
        );
        $this->assertSame(
            $this->app->make(GrpcRouteRegistrar::class),
            $this->app->make(GrpcRouteRegistrar::class),
        );
        $this->assertGreaterThan(0, ResponseFactory::minimumMetadataSize());

        $context = new ServerCallContext(
            Metadata::make(),
            'testing.Service',
            'Call',
            '127.0.0.1:50051',
            null,
            Deadline::fromTimeout(null),
            0,
        );
        $this->app->make(CallContextStore::class)->set($context);

        $this->assertSame($context, $this->app->make(ServerCallContext::class));
    }

    public function testPreservesAnApplicationHealthProviderBinding(): void
    {
        $provider = new ConfigurableHealthStatusProvider;
        $this->app->instance(HealthStatusProvider::class, $provider);

        $this->registerEnabledProvider();

        $this->assertSame($provider, $this->app->make(HealthStatusProvider::class));
    }

    public function testTranslatesValidatedTlsConfiguration(): void
    {
        $config = $this->app->make('config');
        $this->registerEnabledProvider([
            'tls' => [
                'local_cert' => __FILE__,
                'local_pk' => __FILE__,
                'passphrase' => 'secret',
                'verify_peer' => true,
                'allow_self_signed' => true,
                'cafile' => __FILE__,
                'ciphers' => 'HIGH',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
            ],
        ]);

        $server = $config->array('server.servers')[array_key_last($config->array('server.servers'))];

        $this->assertSame(SWOOLE_SOCK_TCP | SWOOLE_SSL, $server['sock_type']);
        $this->assertSame(__FILE__, $server['settings']['ssl_cert_file']);
        $this->assertSame(__FILE__, $server['settings']['ssl_key_file']);
        $this->assertSame('secret', $server['settings']['ssl_passphrase']);
        $this->assertTrue($server['settings']['ssl_verify_peer']);
        $this->assertTrue($server['settings']['ssl_allow_self_signed']);
        $this->assertSame(__FILE__, $server['settings']['ssl_client_cert_file']);
        $this->assertSame('HIGH', $server['settings']['ssl_ciphers']);
        $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_SERVER, $server['settings']['ssl_protocols']);
    }

    #[DataProvider('invalidServerConfiguration')]
    public function testRejectsInvalidEnabledServerConfiguration(
        string $key,
        mixed $value,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->registerEnabledProvider([$key => $value]);
    }

    /**
     * Return invalid enabled-server configuration cases.
     *
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidServerConfiguration(): iterable
    {
        yield 'name' => ['name', '', 'server name cannot be empty'];
        yield 'host' => ['host', "bad\nhost", 'server host is invalid'];
        yield 'low port' => ['port', 0, 'port must be between 1 and 65535'];
        yield 'high port' => ['port', 65536, 'port must be between 1 and 65535'];
        yield 'receive zero' => ['max_receive_message_size', 0, 'receive message limit'];
        yield 'receive overflow' => ['max_receive_message_size', 0xFFFFFFFF - 4, 'receive message limit'];
        yield 'send zero' => ['max_send_message_size', 0, 'send message limit'];
        yield 'send overflow' => ['max_send_message_size', 0x100000000, 'send message limit'];
        yield 'metadata fallback' => ['max_metadata_size', 1, 'too small to emit'];
        yield 'compression' => ['compression', 'deflate', 'compression must be identity, gzip, or null'];
        yield 'route file' => ['routes', '/missing/grpc-routes.php', 'route file [/missing/grpc-routes.php] is not readable'];
        yield 'tls unknown' => ['tls', ['unknown' => true], 'Unknown gRPC server TLS options: unknown'];
        yield 'tls type' => ['tls', ['verify_peer' => null], 'TLS option [verify_peer] must be a boolean'];
        yield 'tls pair' => ['tls', ['local_cert' => __FILE__], 'certificate and private key must be supplied together'];
        yield 'tls without certificate' => ['tls', ['verify_peer' => true], 'TLS options require a certificate'];
        yield 'tls file' => ['tls', [
            'local_cert' => '/missing/grpc.crt',
            'local_pk' => '/missing/grpc.key',
        ], 'TLS certificate file [/missing/grpc.crt] is not readable'];
        yield 'numeric raw key' => ['settings', [0 => true], 'server setting keys must be strings'];
        yield 'owned raw setting' => ['settings', [
            'open_http2_protocol' => false,
        ], 'setting [open_http2_protocol] is owned by first-class configuration'];
        yield 'owned raw tls setting' => ['settings', [
            'ssl_cert_file' => __FILE__,
        ], 'setting [ssl_cert_file] is owned by first-class configuration'];
    }

    public function testPublishesConfigurationAndCanonicalRoutes(): void
    {
        $provider = new GrpcServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertSame([
            dirname(__DIR__, 2) . '/src/grpc/src/../config/grpc.php' => config_path('grpc.php'),
        ], ServiceProvider::pathsToPublish(GrpcServiceProvider::class, 'grpc-config'));
        $this->assertSame([
            dirname(__DIR__, 2) . '/src/grpc/src/../stubs/grpc.php' => base_path('routes/grpc.php'),
        ], ServiceProvider::pathsToPublish(GrpcServiceProvider::class, 'grpc-routes'));
    }

    public function testLoadsIsolatedRoutesDuringServerBootstrapEvenWhenApplicationRoutesAreCached(): void
    {
        $cacheDirectory = ParallelTesting::tempDir('GrpcServiceProviderTest-route-cache');
        $cachePath = $cacheDirectory . '/routes.php';
        $files = new Filesystem;
        $files->deleteDirectory($cacheDirectory);
        $files->ensureDirectoryExists($cacheDirectory);
        $files->put($cachePath, '<?php return [];');
        $_SERVER['APP_ROUTES_CACHE'] = $cachePath;

        try {
            $provider = $this->registerEnabledProvider();

            $this->assertTrue($this->app->routesAreCached());

            $provider->boot();

            $this->assertCount(0, $this->app->make(GrpcRouter::class)->getRoutes()->getRoutes());

            $this->app->make(Server::class)->bootstrapForServer('grpc');

            $routes = $this->app->make(GrpcRouter::class)->getRoutes()->getRoutes();
            $this->assertCount(3, $routes);
            $this->assertSame([
                'grpc.health.v1.Health/Check',
                'grpc.health.v1.Health/List',
                'grpc.health.v1.Health/Watch',
            ], array_map(static fn ($route): string => $route->uri(), $routes));
        } finally {
            unset($_SERVER['APP_ROUTES_CACHE']);
            $files->deleteDirectory($cacheDirectory);
        }
    }

    public function testFinalServerConfigurationRejectsAListenerNameAddedByAnotherProvider(): void
    {
        $config = $this->app->make('config');
        $config->set('server.servers', [[
            'name' => 'grpc',
            'type' => ServerInterface::SERVER_HTTP,
            'host' => '127.0.0.1',
            'port' => 9501,
            'callbacks' => [],
        ]]);
        $this->registerEnabledProvider(['name' => 'grpc']);

        $this->expectException(ServerInvalidArgumentException::class);
        $this->expectExceptionMessage('Server name [grpc] is duplicated.');

        new ServerConfig($config->array('server'));
    }

    /**
     * Register one enabled provider with a readable route file.
     *
     * @param array<string, mixed> $overrides
     */
    private function registerEnabledProvider(array $overrides = []): GrpcServiceProvider
    {
        $config = $this->app->make('config');
        (new GrpcServiceProvider($this->app))->register();
        $server = array_replace_recursive(
            $config->array('grpc.server'),
            [
                'enabled' => true,
                'routes' => self::ROUTES,
            ],
            $overrides,
        );
        $config->set('grpc.server', $server);
        $provider = new GrpcServiceProvider($this->app);
        $provider->register();

        return $provider;
    }
}

class ConfigurableHealthStatusProvider implements HealthStatusProvider
{
    public function statusFor(string $service): ?ServingStatus
    {
        return ServingStatus::NotServing;
    }

    public function statuses(): array
    {
        return ['' => ServingStatus::NotServing];
    }
}
