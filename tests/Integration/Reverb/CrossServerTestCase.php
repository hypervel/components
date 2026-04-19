<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

/**
 * Base test case for cross-server Reverb integration tests.
 *
 * Connects to two separate Reverb instances (ports 19513 and 19514),
 * both with Redis scaling enabled, sharing the same Redis. Proves
 * cross-server Redis pub/sub delivery and RedisSharedState coordination.
 *
 * Start both servers:
 *   REVERB_SERVER_PORT=19513 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 *   REVERB_SERVER_PORT=19514 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 */
abstract class CrossServerTestCase extends ReverbIntegrationTestCase
{
    protected int $serverPort = 19513;

    protected int $serverBPort = 19514;

    /**
     * Connect a WebSocket client to server A (port 19513).
     *
     * @return array{client: Client, socketId: string}
     */
    protected function connectToServerA(?string $appKey = null): array
    {
        return $this->connectToPort($this->getServerPort(), $appKey);
    }

    /**
     * Connect a WebSocket client to server B (port 19514).
     *
     * @return array{client: Client, socketId: string}
     */
    protected function connectToServerB(?string $appKey = null): array
    {
        return $this->connectToPort($this->serverBPort, $appKey);
    }

    /**
     * Subscribe a client to a channel on server A.
     */
    protected function subscribeOnServerA(Client $client, string $socketId, string $channel, ?array $userData = null): string
    {
        return $this->subscribe($client, $socketId, $channel, $userData);
    }

    /**
     * Subscribe a client to a channel on server B.
     */
    protected function subscribeOnServerB(Client $client, string $socketId, string $channel, ?array $userData = null): string
    {
        return $this->subscribe($client, $socketId, $channel, $userData);
    }

    /**
     * Send a signed POST request to server A's HTTP API.
     */
    protected function signedPostToServerA(string $path, array $data = []): string
    {
        return $this->signedPostToPort($this->getServerPort(), $path, $data);
    }

    /**
     * Send a signed POST request to server B's HTTP API.
     */
    protected function signedPostToServerB(string $path, array $data = []): string
    {
        return $this->signedPostToPort($this->serverBPort, $path, $data);
    }

    /**
     * Connect a WebSocket client to a specific port.
     *
     * @return array{client: Client, socketId: string}
     */
    private function connectToPort(int $port, ?string $appKey = null): array
    {
        $appKey ??= $this->appKey;

        $client = new Client($this->getServerHost(), $port);
        $result = $client->upgrade("/app/{$appKey}");

        $this->assertTrue($result, "WebSocket upgrade failed on port {$port}");

        $frame = $client->recv(3);
        $this->assertInstanceOf(Frame::class, $frame, 'Did not receive connection_established');

        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:connection_established', $data['event']);

        $connectionData = json_decode($data['data'], associative: true);

        return [
            'client' => $client,
            'socketId' => $connectionData['socket_id'],
        ];
    }

    /**
     * Send a signed POST request to a specific port's HTTP API.
     */
    private function signedPostToPort(int $port, string $path, array $data = []): string
    {
        $body = json_encode($data);
        $timestamp = time();

        $query = "auth_key={$this->appKey}&auth_timestamp={$timestamp}&auth_version=1.0";
        $params = explode('&', $query);
        sort($params);
        $query = implode('&', $params);

        if ($body !== '') {
            $query .= '&body_md5=' . md5($body);
        }

        $signatureString = "POST\n/apps/{$this->appId}/{$path}\n{$query}";
        $signature = hash_hmac('sha256', $signatureString, $this->appSecret);

        $uri = "/apps/{$this->appId}/{$path}?{$query}&auth_signature={$signature}";

        $httpClient = new Client($this->getServerHost(), $port);
        $httpClient->setHeaders([
            'Content-Type' => 'application/json',
            'Content-Length' => (string) strlen($body),
        ]);
        $httpClient->post($uri, $body);

        $response = $httpClient->body;
        $httpClient->close();

        return $response;
    }
}
