<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Coordinator\Timer;
use Hypervel\Grpc\Console\InstallCommand;
use Hypervel\Grpc\Health\HealthStatusProvider;
use Hypervel\Grpc\Health\ServingHealthStatusProvider;
use Hypervel\Grpc\Server\CallContextStore;
use Hypervel\Grpc\Server\ExceptionMapper;
use Hypervel\Grpc\Server\GrpcRouter;
use Hypervel\Grpc\Server\Middleware\HandleCall;
use Hypervel\Grpc\Server\ResponseFactory;
use Hypervel\Grpc\Server\Server;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Server\Event;
use Hypervel\Server\ServerInterface;
use Hypervel\Server\TlsOptions;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;

class GrpcServiceProvider extends ServiceProvider
{
    /** @var list<string> */
    private const TLS_KEYS = [
        'local_cert',
        'local_pk',
        'passphrase',
        'verify_peer',
        'allow_self_signed',
        'cafile',
        'ciphers',
        'crypto_method',
    ];

    /** @var list<string> */
    private const OWNED_SERVER_SETTINGS = [
        'open_http_protocol',
        'open_http2_protocol',
        'open_websocket_protocol',
        'http_compression',
        'package_max_length',
        'ssl_cert_file',
        'ssl_key_file',
        'ssl_passphrase',
        'ssl_verify_peer',
        'ssl_allow_self_signed',
        'ssl_client_cert_file',
        'ssl_ciphers',
        'ssl_protocols',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/grpc.php', 'grpc');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        $config = $this->app->make('config');

        if (! $config->boolean('grpc.server.enabled')) {
            return;
        }

        $server = $this->serverConfiguration($config);

        $this->registerServerServices($server);
        $this->appendServer($config, $server);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/grpc.php' => config_path('grpc.php'),
            ], ['grpc', 'grpc-config']);

            $this->publishes([
                __DIR__ . '/../stubs/grpc.php' => base_path('routes/grpc.php'),
            ], ['grpc', 'grpc-routes']);
        }

        $config = $this->app->make('config');

        if ($config->boolean('grpc.server.enabled')) {
            require $config->string('grpc.server.routes');
        }
    }

    /**
     * Register services used by the dedicated gRPC listener.
     *
     * @param array<string, mixed> $server
     */
    private function registerServerServices(array $server): void
    {
        $this->app->singletonIf(
            HealthStatusProvider::class,
            ServingHealthStatusProvider::class,
        );
        $this->app->singleton(GrpcRouter::class, fn ($app) => new GrpcRouter(
            $app->make('events'),
            $app,
        ));
        $this->app->singleton(ResponseFactory::class, fn ($app) => new ResponseFactory(
            $app->make(ExceptionMapper::class),
            $app->make(CallContextStore::class),
            $server['max_send_message_size'],
            $server['max_metadata_size'],
        ));
        $this->app->singleton(HandleCall::class, fn ($app) => new HandleCall(
            $app->make(CallContextStore::class),
            $app->make(Timer::class),
            $server['max_receive_message_size'],
            $server['compression'],
        ));
        $this->app->bind(
            ServerCallContext::class,
            fn ($app) => $app->make(CallContextStore::class)->get(),
        );
    }

    /**
     * Append the dedicated gRPC port before server configuration is materialized.
     *
     * @param array<string, mixed> $server
     */
    private function appendServer(ConfigRepository $config, array $server): void
    {
        /** @var array<string, mixed> $tlsConfiguration */
        $tlsConfiguration = $server['tls'];
        $tls = TlsOptions::fromArray($tlsConfiguration);
        $servers = $config->array('server.servers');
        $servers[] = [
            'name' => $server['name'],
            'type' => ServerInterface::SERVER_HTTP,
            'host' => $server['host'],
            'port' => $server['port'],
            'sock_type' => $tls->socketType(SWOOLE_SOCK_TCP),
            'callbacks' => [
                Event::ON_REQUEST => [Server::class, 'onRequest'],
            ],
            'settings' => array_replace(
                $server['settings'],
                $tls->settings(),
                [
                    'open_http_protocol' => true,
                    'open_http2_protocol' => true,
                    'open_websocket_protocol' => false,
                    'http_compression' => false,
                    'package_max_length' => $server['max_receive_message_size'] + 5,
                ],
            ),
        ];

        $config->set('server.servers', $servers);
    }

    /**
     * Validate and normalize the enabled server configuration.
     *
     * @return array<string, mixed>
     */
    private function serverConfiguration(ConfigRepository $config): array
    {
        $name = $config->string('grpc.server.name');
        $host = $config->string('grpc.server.host');
        $port = $config->integer('grpc.server.port');
        $routes = $config->string('grpc.server.routes');
        $maxReceiveMessageSize = $config->integer('grpc.server.max_receive_message_size');
        $maxSendMessageSize = $config->integer('grpc.server.max_send_message_size');
        $maxMetadataSize = $config->integer('grpc.server.max_metadata_size');
        $compression = $this->compression($config->get('grpc.server.compression'));
        $tls = $this->tlsConfiguration($config->array('grpc.server.tls'));
        $settings = $this->serverSettings($config->array('grpc.server.settings'));

        if (trim($name) === '') {
            throw new InvalidArgumentException('The gRPC server name cannot be empty.');
        }

        if ($host === '' || trim($host) !== $host || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
            throw new InvalidArgumentException('The gRPC server host is invalid.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The gRPC server port must be between 1 and 65535.');
        }

        if ($maxReceiveMessageSize < 1 || $maxReceiveMessageSize > 0xFFFFFFFF - 5) {
            throw new InvalidArgumentException(
                'The gRPC server receive message limit must fit the native unsigned 32-bit body limit.',
            );
        }

        if ($maxSendMessageSize < 1 || $maxSendMessageSize > 0xFFFFFFFF) {
            throw new InvalidArgumentException(
                'The gRPC server send message limit must fit an unsigned 32-bit frame.',
            );
        }

        if ($maxMetadataSize < ResponseFactory::minimumMetadataSize()) {
            throw new InvalidArgumentException(
                'The gRPC metadata limit is too small to emit a protocol error response.',
            );
        }

        if (! is_file($routes) || ! is_readable($routes)) {
            throw new InvalidArgumentException("The gRPC route file [{$routes}] is not readable.");
        }

        return [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'routes' => $routes,
            'max_receive_message_size' => $maxReceiveMessageSize,
            'max_send_message_size' => $maxSendMessageSize,
            'max_metadata_size' => $maxMetadataSize,
            'compression' => $compression,
            'tls' => $tls,
            'settings' => $settings,
        ];
    }

    /**
     * Normalize the preferred response compression.
     */
    private function compression(mixed $compression): Compression
    {
        if ($compression === null) {
            return Compression::Identity;
        }

        if ($compression instanceof Compression) {
            return $compression;
        }

        if (is_string($compression) && ($resolved = Compression::tryFrom($compression)) !== null) {
            return $resolved;
        }

        throw new InvalidArgumentException('The gRPC server compression must be identity, gzip, or null.');
    }

    /**
     * Validate the Laravel-style TLS configuration.
     *
     * @param array<array-key, mixed> $tls
     * @return array<string, mixed>
     */
    private function tlsConfiguration(array $tls): array
    {
        $unknownKeys = array_diff(array_keys($tls), self::TLS_KEYS);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException(
                'Unknown gRPC server TLS options: ' . implode(', ', $unknownKeys) . '.',
            );
        }

        $certificate = $this->nullableString($tls, 'local_cert');
        $privateKey = $this->nullableString($tls, 'local_pk');
        $passphrase = $this->nullableString($tls, 'passphrase');
        $clientCa = $this->nullableString($tls, 'cafile');
        $ciphers = $this->nullableString($tls, 'ciphers');
        $verifyPeer = $this->boolean($tls, 'verify_peer');
        $allowSelfSigned = $this->boolean($tls, 'allow_self_signed');
        $cryptoMethod = $tls['crypto_method'] ?? null;

        if ($cryptoMethod !== null && ! is_int($cryptoMethod)) {
            throw new InvalidArgumentException('The gRPC server TLS option [crypto_method] must be an integer or null.');
        }

        if (($certificate === null) !== ($privateKey === null)) {
            throw new InvalidArgumentException(
                'The gRPC server TLS certificate and private key must be supplied together.',
            );
        }

        if ($certificate === null) {
            if ($passphrase !== null
                || $clientCa !== null
                || $ciphers !== null
                || $cryptoMethod !== null
                || $verifyPeer
                || $allowSelfSigned) {
                throw new InvalidArgumentException(
                    'The gRPC server TLS options require a certificate and private key.',
                );
            }
        } else {
            $this->assertReadableFile($certificate, 'certificate');
            $this->assertReadableFile($privateKey, 'private key');
        }

        if ($clientCa !== null) {
            $this->assertReadableFile($clientCa, 'client CA');
        }

        return [
            'local_cert' => $certificate,
            'local_pk' => $privateKey,
            'passphrase' => $passphrase,
            'verify_peer' => $verifyPeer,
            'allow_self_signed' => $allowSelfSigned,
            'cafile' => $clientCa,
            'ciphers' => $ciphers,
            'crypto_method' => $cryptoMethod,
        ];
    }

    /**
     * Validate raw settings not owned by first-class configuration.
     *
     * @param array<array-key, mixed> $settings
     * @return array<string, mixed>
     */
    private function serverSettings(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('The gRPC server setting keys must be strings.');
            }

            if (in_array($key, self::OWNED_SERVER_SETTINGS, true)) {
                throw new InvalidArgumentException(
                    "The gRPC server setting [{$key}] is owned by first-class configuration.",
                );
            }
        }

        return $settings;
    }

    /**
     * Return a nullable string TLS option.
     *
     * @param array<array-key, mixed> $tls
     */
    private function nullableString(array $tls, string $key): ?string
    {
        $value = $tls[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException(
                "The gRPC server TLS option [{$key}] must be a string or null.",
            );
        }

        return $value;
    }

    /**
     * Return a boolean TLS option.
     *
     * @param array<array-key, mixed> $tls
     */
    private function boolean(array $tls, string $key): bool
    {
        $value = $tls[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                "The gRPC server TLS option [{$key}] must be a boolean.",
            );
        }

        return $value;
    }

    /**
     * Require a readable TLS file.
     */
    private function assertReadableFile(string $path, string $description): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(
                "The gRPC server TLS {$description} file [{$path}] is not readable.",
            );
        }
    }
}
