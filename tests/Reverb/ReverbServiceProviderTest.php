<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use ReflectionMethod;
use ReflectionProperty;

class ReverbServiceProviderTest extends ReverbTestCase
{
    public function testRegistersWebSocketServerWithoutTls()
    {
        $server = $this->registerReverbServer([
            'options' => [
                'tls' => [],
            ],
        ]);

        $this->assertSame(SWOOLE_SOCK_TCP, $server['sock_type']);
        $this->assertSame([
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
        ], $server['settings']);
    }

    public function testRegistersWebSocketServerWithTls()
    {
        $server = $this->registerReverbServer([
            'options' => [
                'tls' => [
                    'local_cert' => '/path/to/certificate.crt',
                    'local_pk' => '/path/to/private.key',
                    'passphrase' => 'secret',
                    'verify_peer' => false,
                    'allow_self_signed' => true,
                    'cafile' => '/path/to/ca.pem',
                    'ciphers' => 'HIGH:!aNULL:!MD5',
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
                ],
            ],
        ]);

        $this->assertSame(SWOOLE_SOCK_TCP | SWOOLE_SSL, $server['sock_type']);
        $this->assertSame('/path/to/certificate.crt', $server['settings']['ssl_cert_file']);
        $this->assertSame('/path/to/private.key', $server['settings']['ssl_key_file']);
        $this->assertSame('secret', $server['settings']['ssl_passphrase']);
        $this->assertFalse($server['settings']['ssl_verify_peer']);
        $this->assertTrue($server['settings']['ssl_allow_self_signed']);
        $this->assertSame('/path/to/ca.pem', $server['settings']['ssl_client_cert_file']);
        $this->assertSame('HIGH:!aNULL:!MD5', $server['settings']['ssl_ciphers']);
        $this->assertSame(STREAM_CRYPTO_METHOD_TLSv1_2_SERVER, $server['settings']['ssl_protocols']);
    }

    public function testRegistersWebSocketServerWithSwooleTlsSettings()
    {
        $server = $this->registerReverbServer([
            'options' => [
                'tls' => [
                    'ssl_cert_file' => '/path/to/certificate.crt',
                    'ssl_key_file' => '/path/to/private.key',
                    'ssl_ciphers' => 'TLS_AES_256_GCM_SHA384',
                ],
            ],
        ]);

        $this->assertSame(SWOOLE_SOCK_TCP | SWOOLE_SSL, $server['sock_type']);
        $this->assertSame('/path/to/certificate.crt', $server['settings']['ssl_cert_file']);
        $this->assertSame('/path/to/private.key', $server['settings']['ssl_key_file']);
        $this->assertSame('TLS_AES_256_GCM_SHA384', $server['settings']['ssl_ciphers']);
    }

    public function testWebhookBatchBufferDefaultsToReverbRedisConnection()
    {
        $buffer = $this->app->make(WebhookBatchBuffer::class);

        $this->assertSame('reverb', $this->bufferRedisConnection($buffer)->getName());
    }

    public function testWebhookBatchBufferUsesConfiguredScalingRedisConnection()
    {
        $this->app->make('config')->set('reverb.servers.reverb.scaling.connection', 'queue');

        $this->app->forgetInstance(WebhookBatchBuffer::class);

        $buffer = $this->app->make(WebhookBatchBuffer::class);

        $this->assertSame('queue', $this->bufferRedisConnection($buffer)->getName());
    }

    protected function bufferRedisConnection(WebhookBatchBuffer $buffer): RedisProxy
    {
        $property = new ReflectionProperty($buffer, 'redis');

        return $property->getValue($buffer);
    }

    protected function registerReverbServer(array $serverConfig): array
    {
        $config = $this->app->make('config');

        $config->set('server.servers', []);
        $config->set('reverb.servers.reverb', array_replace_recursive(
            $config->get('reverb.servers.reverb', []),
            $serverConfig
        ));

        $provider = new ReverbServiceProvider($this->app);

        $method = new ReflectionMethod($provider, 'registerWebSocketServer');
        $method->invoke($provider);

        return $config->get('server.servers')[0];
    }
}
