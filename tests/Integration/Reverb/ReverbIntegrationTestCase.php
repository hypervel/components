<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

/**
 * Base test case for Reverb integration tests.
 *
 * Requires a running Reverb test server on port 19510.
 * Start it with: php tests/Integration/Reverb/server.php
 *
 * Tests auto-skip when the server is not available.
 */
abstract class ReverbIntegrationTestCase extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19510;

    /**
     * App credentials for the current test worker.
     *
     * In parallel mode (TEST_TOKEN set), each worker gets its own app
     * to prevent cross-worker interference on the shared test server.
     * In sequential mode, uses the default app.
     */
    protected string $appKey = 'reverb-key';

    protected string $appSecret = 'reverb-secret';

    protected string $appId = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureParallelAppCredentials();
        $this->setUpInteractsWithServer();
    }

    /**
     * Set per-worker app credentials for parallel test isolation.
     *
     * Each parallel worker gets a unique app ID, key, and secret derived
     * from TEST_TOKEN. The test servers pre-register matching apps for
     * each possible worker. In sequential mode this is a no-op.
     */
    protected function configureParallelAppCredentials(): void
    {
        $token = env('TEST_TOKEN');

        if ($token === null) {
            return;
        }

        $number = (int) $token;
        $this->appId = (string) (200000 + $number);
        $this->appKey = "reverb-parallel-{$number}";
        $this->appSecret = "reverb-parallel-secret-{$number}";
    }

    /**
     * Open a WebSocket connection to the Reverb server.
     *
     * Returns the client after receiving the connection_established message.
     * The socket_id is extracted and returned alongside the client.
     *
     * @return array{client: Client, socketId: string}
     */
    protected function connect(?string $appKey = null): array
    {
        $appKey ??= $this->appKey;

        $client = new Client($this->getServerHost(), $this->getServerPort());
        $result = $client->upgrade("/app/{$appKey}");

        $this->assertTrue($result, 'WebSocket upgrade failed');

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
     * Subscribe a WebSocket client to a channel.
     *
     * Returns the subscription response data string.
     */
    protected function subscribe(
        Client $client,
        string $socketId,
        string $channel,
        ?array $userData = null,
    ): string {
        $data = ['channel' => $channel];

        $channelData = $userData !== null ? json_encode($userData) : null;

        if (str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-')) {
            $signature = "{$socketId}:{$channel}";
            if ($channelData !== null) {
                $signature .= ":{$channelData}";
            }
            $data['auth'] = $this->appKey . ':' . hash_hmac('sha256', $signature, $this->appSecret);
        }

        if ($channelData !== null) {
            $data['channel_data'] = $channelData;
        }

        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => $data,
        ]));

        $frame = $client->recv(3);
        $this->assertInstanceOf(Frame::class, $frame, "No subscription response for {$channel}");

        return $frame->data;
    }

    /**
     * Send a message on a WebSocket client and return the next received frame data.
     */
    protected function send(Client $client, array $message, float $timeout = 3): ?string
    {
        $client->push(json_encode($message));

        $frame = $client->recv($timeout);

        return $frame instanceof Frame ? $frame->data : null;
    }

    /**
     * Receive the next frame from a client, or null on timeout.
     */
    protected function recv(Client $client, float $timeout = 3): ?string
    {
        $frame = $client->recv($timeout);

        return $frame instanceof Frame ? $frame->data : null;
    }

    /**
     * Receive all pending frames from a client until timeout.
     *
     * @return list<string>
     */
    protected function recvAll(Client $client, float $timeout = 0.5): array
    {
        $messages = [];

        while (true) {
            $frame = $client->recv($timeout);

            if (! $frame instanceof Frame) {
                break;
            }

            $messages[] = $frame->data;
        }

        return $messages;
    }

    /**
     * Disconnect a WebSocket client.
     */
    protected function disconnect(Client $client): void
    {
        $client->close();
    }

    /**
     * Send a signed GET request to the Reverb HTTP API via Swoole client.
     *
     * @return array{status: int, body: string, headers: array}
     */
    protected function signedServerRequest(
        string $path,
        ?string $appId = null,
        ?string $key = null,
        ?string $secret = null,
    ): array {
        $appId ??= $this->appId;
        $key ??= $this->appKey;
        $secret ??= $this->appSecret;

        $uri = $this->buildSignedServerUri('GET', $path, '', $appId, $key, $secret);

        $client = new Client($this->getServerHost(), $this->getServerPort());
        $client->get($uri);

        $result = ['status' => $client->getStatusCode(), 'body' => $client->getBody(), 'headers' => $client->getHeaders() ?: []];
        $client->close();

        return $result;
    }

    /**
     * Send a signed POST request to the Reverb HTTP API via Swoole client.
     *
     * @return array{status: int, body: string, headers: array}
     */
    protected function signedServerPostRequest(
        string $path,
        ?array $data = [],
        ?string $appId = null,
        ?string $key = null,
        ?string $secret = null,
    ): array {
        $appId ??= $this->appId;
        $key ??= $this->appKey;
        $secret ??= $this->appSecret;

        $body = $data !== null ? json_encode($data) : '';

        $uri = $this->buildSignedServerUri('POST', $path, $body, $appId, $key, $secret);

        $client = new Client($this->getServerHost(), $this->getServerPort());
        $client->setHeaders([
            'Content-Type' => 'application/json',
            'Content-Length' => (string) strlen($body),
        ]);
        $client->post($uri, $body);

        $result = ['status' => $client->getStatusCode(), 'body' => $client->getBody(), 'headers' => $client->getHeaders() ?: []];
        $client->close();

        return $result;
    }

    /**
     * Trigger an event via the HTTP API.
     */
    protected function triggerEvent(string $channel, string $event, array $data = []): void
    {
        $result = $this->signedServerPostRequest('events', [
            'name' => $event,
            'channel' => $channel,
            'data' => json_encode($data),
        ]);

        $this->assertSame(200, $result['status'], "triggerEvent failed: {$result['body']}");
    }

    /**
     * Build a signed URI for Swoole HTTP client requests.
     */
    private function buildSignedServerUri(
        string $method,
        string $path,
        string $body,
        string $appId,
        string $key,
        string $secret,
    ): string {
        $timestamp = time();

        $queryString = str_contains($path, '?') ? substr($path, strpos($path, '?') + 1) : '';
        $path = str_contains($path, '?') ? substr($path, 0, strpos($path, '?')) : $path;

        $auth = "auth_key={$key}&auth_timestamp={$timestamp}&auth_version=1.0";
        $query = $queryString !== '' ? "{$queryString}&{$auth}" : $auth;

        $params = explode('&', $query);
        sort($params);
        $query = implode('&', $params);

        if ($body !== '') {
            $query .= '&body_md5=' . md5($body);
        }

        $signatureString = "{$method}\n/apps/{$appId}/{$path}\n{$query}";
        $signature = hash_hmac('sha256', $signatureString, $secret);

        return "/apps/{$appId}/{$path}?{$query}&auth_signature={$signature}";
    }

    /**
     * Reset the test server's faked queue to clear recorded jobs.
     */
    protected function resetQueueFake(): void
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $client->post('/_test/queue-reset', '');
        $client->close();
    }

    /**
     * Get the WebhookDeliveryJob payloads captured by the server's faked queue.
     *
     * @return list<array{event: string, appId: string, channel: ?string, url: string}>
     */
    protected function getQueuedWebhookJobs(): array
    {
        $client = new Client($this->getServerHost(), $this->getServerPort());
        $client->get('/_test/queued-jobs');

        $body = json_decode($client->getBody(), associative: true);
        $client->close();

        return $body['jobs'] ?? [];
    }
}
