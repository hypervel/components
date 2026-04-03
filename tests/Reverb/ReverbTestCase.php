<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Mockery as m;
use Swoole\Server;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class ReverbTestCase extends TestCase
{
    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ReverbServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('reverb.apps.apps', [
            [
                'key' => 'reverb-key',
                'secret' => 'reverb-secret',
                'app_id' => '123456',
                'options' => [
                    'host' => 'localhost',
                    'port' => 443,
                    'scheme' => 'https',
                    'useTLS' => true,
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10_000,
                'accept_client_events_from' => 'members',
            ],
        ]);

        $server = m::mock(Server::class);
        $server->shouldReceive('sendMessage')->zeroOrMoreTimes();
        $server->setting = ['worker_num' => 1];
        $server->worker_id = 0;
        $app->instance(Server::class, $server);
    }

    /**
     * Create a defined number of channel connections.
     *
     * @return array<int, ChannelConnection>
     */
    protected static function factory(int $count = 1, array $data = []): array
    {
        return Collection::make(range(1, $count))->map(function () use ($data) {
            return new ChannelConnection(new FakeConnection((string) Str::uuid()), $data);
        })->all();
    }

    /**
     * Generate a valid Pusher authentication signature.
     */
    protected static function validAuth(string $connectionId, string $channel, ?string $data = null): string
    {
        $signature = "{$connectionId}:{$channel}";

        if ($data) {
            $signature .= ":{$data}";
        }

        return 'app-key:' . hash_hmac('sha256', $signature, 'reverb-secret');
    }

    /**
     * Return a scoped channel manager for the default app.
     */
    protected function channels(?Application $app = null): ScopedChannelManager
    {
        return $this->app->make(ChannelManager::class)
            ->for($app ?: $this->app->make(ApplicationProvider::class)->all()->first());
    }

    /**
     * Subscribe a FakeConnection to a channel via the protocol layer.
     *
     * Returns the FakeConnection so tests can inspect received messages
     * or use its ID for socket_id exclusion.
     */
    protected function subscribeConnection(
        string $channel,
        ?array $userData = null,
        ?FakeConnection $connection = null,
        ?Application $app = null,
    ): FakeConnection {
        $connection ??= new FakeConnection();
        $app ??= $this->app->make(ApplicationProvider::class)->all()->first();

        $handler = new EventHandler($this->app->make(ChannelManager::class));

        $data = $userData !== null ? json_encode($userData) : null;
        $auth = null;

        if (Str::startsWith($channel, ['private-', 'presence-'])) {
            $auth = static::validAuth($connection->id(), $channel, $data);
        }

        $handler->handle($connection, 'pusher:connection_established');
        $handler->subscribe($connection, $channel, $auth, $data);

        // Clear received messages so tests start with a clean slate
        $connection->resetReceived();

        return $connection;
    }

    /**
     * Send a signed GET request to a Reverb HTTP API endpoint.
     *
     * Dispatches through the isolated ReverbRouter, matching the
     * production path (not the global app Router).
     */
    protected function signedRequest(
        string $path,
        string $appId = '123456',
        string $key = 'reverb-key',
        string $secret = 'reverb-secret',
    ): TestResponse {
        $uri = $this->buildSignedUri('GET', $path, '', $appId, $key, $secret);

        return $this->dispatchThroughReverbRouter(
            Request::create($uri, 'GET')
        );
    }

    /**
     * Send a signed POST request to a Reverb HTTP API endpoint.
     *
     * Dispatches through the isolated ReverbRouter, matching the
     * production path (not the global app Router).
     */
    protected function signedPostRequest(
        string $path,
        ?array $data = [],
        string $appId = '123456',
        string $key = 'reverb-key',
        string $secret = 'reverb-secret',
    ): TestResponse {
        $body = $data !== null ? json_encode($data) : '';

        $uri = $this->buildSignedUri('POST', $path, $body, $appId, $key, $secret);

        return $this->dispatchThroughReverbRouter(
            Request::create($uri, 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($body),
            ], content: $body)
        );
    }

    /**
     * Send a GET request through the isolated ReverbRouter.
     */
    protected function reverbGet(string $uri): TestResponse
    {
        return $this->dispatchThroughReverbRouter(
            Request::create($uri, 'GET')
        );
    }

    /**
     * Send a request through the isolated ReverbRouter.
     */
    protected function reverbCall(
        string $method,
        string $uri,
        array $server = [],
        ?string $content = null,
    ): TestResponse {
        return $this->dispatchThroughReverbRouter(
            Request::create($uri, $method, server: $server, content: $content)
        );
    }

    /**
     * Dispatch a request through the isolated ReverbRouter.
     *
     * Mirrors the production HttpServer::onRequest path, including
     * exception handling via the app's ExceptionHandler.
     */
    protected function dispatchThroughReverbRouter(Request $request): TestResponse
    {
        RequestContext::set($request);

        try {
            $response = $this->app->make(ReverbRouter::class)->dispatch($request);
        } catch (Throwable $throwable) {
            $handler = $this->app->make(\Hypervel\Contracts\Debug\ExceptionHandler::class);
            $response = $handler->render($request, $throwable);
        }

        return TestResponse::fromBaseResponse($response);
    }

    /**
     * Build a signed URI with Pusher authentication query parameters.
     *
     * The signature string format is: "METHOD\n/apps/{appId}/{path}\n{sorted_query}"
     * matching the Pusher HTTP API authentication spec.
     */
    private function buildSignedUri(
        string $method,
        string $path,
        string $body,
        string $appId,
        string $key,
        string $secret,
    ): string {
        $timestamp = time();

        // Separate existing query params from path
        $queryString = Str::contains($path, '?') ? Str::after($path, '?') : '';
        $path = Str::before($path, '?');

        // Build auth params
        $auth = "auth_key={$key}&auth_timestamp={$timestamp}&auth_version=1.0";
        $query = $queryString !== '' ? "{$queryString}&{$auth}" : $auth;

        // Sort query params
        $params = explode('&', $query);
        sort($params);
        $query = implode('&', $params);

        // Add body MD5 if body is present
        if ($body !== '') {
            $query .= '&body_md5=' . md5($body);
        }

        // Compute signature — path is WITHOUT any configured prefix,
        // matching how Pusher client SDKs sign requests
        $signatureString = "{$method}\n/apps/{$appId}/{$path}\n{$query}";
        $signature = hash_hmac('sha256', $signatureString, $secret);

        return "/apps/{$appId}/{$path}?{$query}&auth_signature={$signature}";
    }
}
