<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Engine\Channel;
use Hypervel\Http\Client\Request;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\ClientManager;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Client\Transport\HttpTransport;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Mcp\WebClient;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\Fixtures\Client\ThrowingTransport;
use Hypervel\Tests\Mcp\TestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Tests\Mcp\Fixtures\discoverResponse;
use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;
use function Hypervel\Tests\Mcp\Fixtures\pingResponse;

/**
 * Create a tool-list response for a registered client.
 */
function toolsResponse(int $id): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'tools' => [['name' => 'add', 'description' => 'Adds two numbers']],
        ],
    ]);
}

class ClientManagerTest extends TestCase
{
    public function testRegistersANamedClientAndResolvesItByName(): void
    {
        Mcp::registerClient('everything', fn (): Client => new Client(new FakeTransport));

        $this->assertInstanceOf(Client::class, Mcp::client('everything'));
    }

    public function testResolvesAndMemoizesARegisteredClientPerRequest(): void
    {
        $resolved = 0;

        Mcp::registerClient('everything', function () use (&$resolved): Client {
            ++$resolved;

            return new Client(new FakeTransport);
        });

        $first = Mcp::client('everything');
        $second = Mcp::client('everything');

        $this->assertSame($first, $second);
        $this->assertSame(1, $resolved);
    }

    public function testThrowsWhenResolvingAnUnregisteredClient(): void
    {
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('MCP client [missing] has not been registered.');
        Mcp::client('missing');
    }

    public function testResolvesAFreshInstanceWhenTheSameNameIsReregistered(): void
    {
        Mcp::registerClient('everything', fn (): Client => new Client(new FakeTransport));
        $first = Mcp::client('everything');

        Mcp::registerClient('everything', fn (): Client => new Client(new FakeTransport));
        $second = Mcp::client('everything');

        $this->assertNotSame($first, $second);
    }

    public function testDisconnectsAResolvedClientWhenItsNameIsReregistered(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();

        Mcp::registerClient('everything', fn (): Client => new Client($transport));
        Mcp::client('everything')->connect();

        $this->assertTrue($transport->connected);

        Mcp::registerClient('everything', fn (): Client => new Client(new FakeTransport));

        $this->assertFalse($transport->connected);
    }

    public function testDisconnectsEveryResolvedClientAndReresolvesAfterwards(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();

        $resolved = 0;
        Mcp::registerClient('everything', function () use (&$resolved, $transport): Client {
            ++$resolved;

            return new Client($transport);
        });

        $first = Mcp::client('everything');
        $first->connect();

        $this->assertTrue($transport->connected);

        app(ClientManager::class)->disconnectAll();

        $this->assertFalse($transport->connected);

        $second = Mcp::client('everything');

        $this->assertSame(2, $resolved);
        $this->assertNotSame($first, $second);
    }

    public function testKeepsDisconnectingOtherClientsWhenOneThrowsDuringTeardown(): void
    {
        $healthy = new FakeTransport;
        $healthy->responses[] = initializeResponse();

        Mcp::registerClient('broken', fn (): Client => new Client(new ThrowingTransport));
        Mcp::registerClient('healthy', fn (): Client => new Client($healthy));

        Mcp::client('broken');
        Mcp::client('healthy')->connect();

        $this->assertTrue($healthy->connected);

        app(ClientManager::class)->disconnectAll();

        $this->assertFalse($healthy->connected);
    }

    public function testFetchesAFreshToolsListOnEveryResolution(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = toolsResponse(2);
        $transport->responses[] = toolsResponse(3);

        Mcp::registerClient('everything', fn (): Client => new Client($transport));

        $this->assertSame(['add'], Mcp::client('everything')->tools()->keys()->all());
        $this->assertSame(['add'], Mcp::client('everything')->tools()->keys()->all());
        $this->assertEmpty($transport->responses);
    }

    public function testReturnsTheGivenDefaultFromANamedClientWhenAuthorizationIsRequired(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 401),
        ]);

        Mcp::registerClient('nightwatch', fn (): Client => new Client(new HttpTransport('https://mcp.test/mcp')));

        $tools = Mcp::client('nightwatch')->tools(default: []);

        $this->assertInstanceOf(Collection::class, $tools);
        $this->assertEmpty($tools);
    }

    public function testRethrowsTheAuthorizationExceptionFromANamedClientWhenNoDefaultIsGiven(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 401),
        ]);

        Mcp::registerClient('nightwatch', fn (): Client => new Client(new HttpTransport('https://mcp.test/mcp')));

        $this->expectException(AuthorizationRequiredException::class);
        Mcp::client('nightwatch')->tools();
    }

    public function testResolvesASubclassedClientAndPreservesFluentChaining(): void
    {
        $web = new WebClient(new HttpTransport('https://example.test/mcp'));

        Mcp::registerClient('remote', fn (): WebClient => $web);

        $client = Mcp::client('remote');

        $this->assertSame($web, $client);
        $this->assertSame($client, $client->withToken('secret'));
    }

    public function testAppliesAToolsLimitOnTheResolvedClient(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = toolsResponse(2);

        Mcp::registerClient('everything', fn (): Client => new Client($transport));

        $this->assertSame(['add'], Mcp::client('everything')->tools(1)->keys()->all());
        $this->assertEmpty($transport->responses);
    }

    public function testExposesCallToolPingConnectedInitializeResultAndWithTimeoutOnTheResolvedClient(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = pingResponse(2);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['content' => [['type' => 'text', 'text' => 'hi']], 'isError' => false],
        ]);

        Mcp::registerClient('everything', fn (): Client => new Client($transport));

        $client = Mcp::client('everything')->withTimeout(0.5);

        $this->assertFalse($client->connected());

        $client->ping();

        $this->assertTrue($client->connected());
        $this->assertSame('Test Server', $client->initializeResult()?->serverInfo->name);
        $this->assertSame(0.5, $transport->timeoutSeconds);
        $this->assertSame('hi', $client->callTool('say-hi')->text());
    }

    public function testReturnsAToolsListTheApplicationCanCacheAndRestore(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = toolsResponse(2);

        Mcp::registerClient('everything', fn (): Client => new Client($transport));

        $tools = Mcp::client('everything')->tools();

        $restored = unserialize(serialize($tools));

        $this->assertSame(['add'], $restored->keys()->all());
        $this->assertSame('Adds two numbers', $restored['add']->description);
    }

    public function testSerializesANamedClientAsItsNameOnlyAndReresolvesItWhenARestoredToolIsCalled(): void
    {
        $resolutions = 0;

        Mcp::registerClient('everything', function () use (&$resolutions): Client {
            ++$resolutions;

            $transport = new FakeTransport;
            $transport->responses[] = initializeResponse();
            $transport->responses[] = $resolutions === 1
                ? toolsResponse(2)
                : json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'result' => ['content' => [['type' => 'text', 'text' => 'added']], 'isError' => false],
                ]);

            return new Client($transport);
        });

        $payload = serialize(Mcp::client('everything')->tools());

        $this->assertStringContainsString('everything', $payload);
        $this->assertStringNotContainsString('secret', $payload);

        app(ClientManager::class)->disconnectAll();

        $restored = unserialize($payload);

        $this->assertSame('added', $restored['add']->call()->text());
        $this->assertSame(2, $resolutions);
    }

    public function testGivesARestoredNamedClientItsOwnTransportInsteadOfAliasingTheCachedOne(): void
    {
        Mcp::registerClient('everything', fn (): Client => new Client(new FakeTransport));

        $live = Mcp::client('everything');

        $restored = unserialize(serialize($live));

        $transport = new ReflectionProperty(Client::class, 'transport');

        $this->assertNotSame($live, $restored);
        $this->assertNotSame($transport->getValue($live), $transport->getValue($restored));
    }

    public function testDoesNotCacheToolsOnTheResolvedClient(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = toolsResponse(2);
        $transport->responses[] = toolsResponse(3);

        $client = new Client($transport);
        $client->tools();
        $client->tools();

        $this->assertEmpty($transport->responses);
    }

    public function testStillInstallsAFreshFactoryWhenAnOldClientFailsToDisconnect(): void
    {
        $broken = new ThrowingTransport;
        $broken->responses[] = initializeResponse();

        Mcp::registerClient('everything', fn (): Client => new Client($broken));
        $first = Mcp::client('everything');
        $first->connect();

        $fresh = new FakeTransport;
        $fresh->responses[] = initializeResponse();
        Mcp::registerClient('everything', fn (): Client => new Client($fresh));

        $second = Mcp::client('everything');
        $second->connect();

        $this->assertNotSame($first, $second);
        $this->assertTrue($fresh->connected);
    }

    public function testPropagatesALiveFetchFailure(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['tools' => 'not-an-array'],
        ]);

        Mcp::registerClient('everything', fn (): Client => new Client($transport));

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid tools/list response from server.');
        try {
            Mcp::client('everything')->tools();
        } finally {
            $this->assertEmpty($transport->responses);
        }
    }

    public function testProvidesAReadOnlyGithubClientFromConfig(): void
    {
        config(['mcp.clients.github.token' => 'gh-token']);

        $client = Mcp::client('github');

        $this->assertInstanceOf(WebClient::class, $client);
        $this->assertPartialArray([
            'url' => 'https://api.githubcopilot.com/mcp/',
            'token' => 'gh-token',
            'headers' => ['X-MCP-Readonly' => 'true'],
        ], transportRecipe($client));
    }

    public function testProvidesALinearClientFromConfig(): void
    {
        config(['mcp.clients.linear.token' => 'lin-token']);

        $this->assertPartialArray([
            'url' => 'https://mcp.linear.app/mcp',
            'token' => 'lin-token',
        ], transportRecipe(Mcp::client('linear')));
    }

    public function testProvidesAHeadlessPlaywrightClientFromConfig(): void
    {
        $this->assertPartialArray([
            'command' => 'npx',
            'args' => ['-y', '@playwright/mcp@latest', '--headless'],
        ], transportRecipe(Mcp::client('playwright')));
    }

    public function testResolvesAnyClientDefinedInConfig(): void
    {
        config(['mcp.clients.sentry' => [
            'url' => 'https://mcp.sentry.dev/mcp',
            'token' => 'sentry-token',
            'headers' => ['X-Org' => 'hypervel', 'X-Empty' => null],
            'timeout' => 5,
        ]]);

        $client = Mcp::client('sentry');

        $this->assertPartialArray([
            'url' => 'https://mcp.sentry.dev/mcp',
            'token' => 'sentry-token',
            'headers' => ['X-Org' => 'hypervel'],
            'timeoutSeconds' => 5.0,
        ], transportRecipe($client));
    }

    public function testResolvesALocalClientDefinedInConfig(): void
    {
        config(['mcp.clients.weather' => [
            'command' => 'php',
            'args' => ['hypervel', 'mcp:start', 'weather'],
            'env' => ['WEATHER_API_KEY' => 'secret', 'UNSET' => null],
            'timeout' => 10,
        ]]);

        $this->assertPartialArray([
            'command' => 'php',
            'args' => ['hypervel', 'mcp:start', 'weather'],
            'env' => ['WEATHER_API_KEY' => 'secret'],
            'timeoutSeconds' => 10.0,
        ], transportRecipe(Mcp::client('weather')));
    }

    public function testAppliesOauthCacheAndProtocolVersionFromConfig(): void
    {
        config(['mcp.clients.sentry' => [
            'url' => 'https://mcp.sentry.dev/mcp',
            'oauth' => [
                'client_id' => 'sentry-id',
                'client_secret' => 'sentry-secret',
                'scope' => 'org:read',
                'redirect_uri' => 'https://app.test/callback',
            ],
            'cache' => ['store' => 'array', 'for' => 'tenant-a'],
            'protocol_version' => '2025-06-18',
        ]]);

        $client = Mcp::client('sentry');
        $protocol = (new ReflectionProperty(Client::class, 'protocol'))->getValue($client);

        $this->assertSame([
            'clientId' => 'sentry-id',
            'clientSecret' => 'sentry-secret',
            'scope' => 'org:read',
            'redirectUri' => 'https://app.test/callback',
        ], get_object_vars((new ReflectionProperty(WebClient::class, 'oAuthConfig'))->getValue($client)));
        $this->assertSame('array', $protocol->cache()->store);
        $this->assertSame('tenant-a', $protocol->cache()->for);
        $this->assertSame(ProtocolVersion::V2025_06_18, $protocol->pinnedProtocolVersion());
    }

    public function testEnablesTheDefaultCacheWhenCacheIsTrue(): void
    {
        config(['mcp.clients.local' => ['command' => 'php', 'cache' => true]]);

        $protocol = (new ReflectionProperty(Client::class, 'protocol'))->getValue(Mcp::client('local'));

        $this->assertNull($protocol->cache()->store);
        $this->assertNull($protocol->cache()->for);
    }

    public function testThrowsWhenAConfigClientDefinesNeitherAUrlNorACommand(): void
    {
        config(['mcp.clients.broken' => ['token' => 'secret']]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('MCP client [broken] must define a [url] or [command].');
        Mcp::client('broken');
    }

    public function testPrefersARegisteredClientOverTheConfigEntry(): void
    {
        $web = new WebClient(new HttpTransport('https://custom.test/mcp'));

        Mcp::registerClient('github', fn (): WebClient => $web);

        $this->assertSame($web, Mcp::client('github'));
    }

    public function testFiltersToolsWithOnlyAndExceptPatterns(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = listToolsResponse(2, ['get_issue', 'list_issues', 'delete_issue', 'create_branch']);

        $client = (new Client($transport))->onlyTools(['*_issue', 'list_*'])->exceptTools(['delete_*']);

        $this->assertSame(['get_issue', 'list_issues'], $client->tools()->keys()->all());
    }

    public function testRefusesToCallAnExcludedTool(): void
    {
        $client = (new Client(new FakeTransport))->exceptTools(['delete_*']);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('MCP tool [delete_issue] is not allowed on this client.');
        $client->callTool('delete_issue');
    }

    public function testAppliesToolFiltersFromConfig(): void
    {
        config(['mcp.clients.local' => [
            'command' => 'php',
            'tools' => ['only' => ['say-*'], 'except' => ['say-bye']],
        ]]);

        $client = Mcp::client('local');

        $this->assertTrue($client->allowsTool('say-hi'));
        $this->assertFalse($client->allowsTool('say-bye'));
        $this->assertFalse($client->allowsTool('add'));
    }

    public function testKeepsToolFiltersWhenAnUnnamedClientIsSerialized(): void
    {
        $client = Client::local('php')->onlyTools(['say-*'])->exceptTools(['say-bye']);

        $restored = unserialize(serialize($client));

        $this->assertTrue($restored->allowsTool('say-hi'));
        $this->assertFalse($restored->allowsTool('say-bye'));
        $this->assertFalse($restored->allowsTool('add'));
    }

    public function testKeepsToolFiltersWhenANamedClientIsRestored(): void
    {
        config(['mcp.clients.local' => ['command' => 'php', 'tools' => ['except' => ['say-bye']]]]);

        $restored = unserialize(serialize(Mcp::client('local')));

        $this->assertFalse($restored->allowsTool('say-bye'));
        $this->assertTrue($restored->allowsTool('say-hi'));
    }

    public function testRestoresNamedOAuthConfigurationFromTheSameFactoryAsTheTransport(): void
    {
        $builds = 0;
        Mcp::registerClient('oauth', function () use (&$builds): WebClient {
            ++$builds;

            return Client::web('https://mcp.test/' . $builds)
                ->withOAuth(clientId: 'client-' . $builds, clientSecret: 'secret-' . $builds);
        });

        $payload = serialize(Mcp::client('oauth'));
        $restored = unserialize($payload);
        $config = (new ReflectionProperty(WebClient::class, 'oAuthConfig'))->getValue($restored);

        $this->assertSame(2, $builds);
        $this->assertSame('https://mcp.test/2', transportRecipe($restored)['url']);
        $this->assertSame('client-2', $config->clientId);
        $this->assertSame('secret-2', $config->clientSecret);
        $this->assertStringNotContainsString('secret-', $payload);
        $this->assertFalse($restored->connected());
    }

    public function testCopiedChildContextsOwnIndependentClientsAndCleanup(): void
    {
        Http::fake(fn (Request $request): PromiseInterface => Http::response(
            ($request['method'] ?? null) === 'server/discover'
                ? discoverResponse($request['id'])
                : pingResponse($request['id']),
        ));
        $manager = app(ClientManager::class);
        $manager->registerClient('remote', fn (): WebClient => Client::web('https://mcp.test/mcp')
            ->withToken(CoroutineContext::get('mcp-test.token'))
            ->withProtocolVersion(ProtocolVersion::LATEST));
        CoroutineContext::set('mcp-test.token', 'parent');
        $parent = $manager->client('remote')->connect();
        $ready = new Channel(1);
        $closed = new Channel(1);

        try {
            [$first, $second] = parallel([
                function () use ($manager, $ready, $closed): Client {
                    CoroutineContext::set('mcp-test.token', 'first');
                    $client = $manager->client('remote')->connect();
                    $this->assertTrue($ready->pop(1));
                    $manager->disconnectAll();
                    $this->assertTrue($closed->push(true, 1));

                    return $client;
                },
                function () use ($manager, $ready, $closed): Client {
                    CoroutineContext::set('mcp-test.token', 'second');
                    $client = $manager->client('remote')->connect();
                    $this->assertTrue($ready->push(true, 1));
                    $this->assertTrue($closed->pop(1));
                    $this->assertTrue($client->connected());
                    $client->ping();

                    return $client;
                },
            ], copyContext: true);

            $this->assertNotSame($parent, $first);
            $this->assertNotSame($first, $second);
            $this->assertTrue($parent->connected());
            $this->assertFalse($first->connected());
            $this->assertFalse($second->connected());
            $this->assertSame('first', transportRecipe($first)['token']);
            $this->assertSame('second', transportRecipe($second)['token']);
            Http::assertSent(fn (Request $request): bool => ($request['method'] ?? null) === 'server/discover'
                && $request['id'] === 2
                && $request->hasHeader('Authorization', 'Bearer second'));
        } finally {
            $manager->disconnectAll();
            $ready->close();
            $closed->close();
        }
    }

    public function testTeardownVisitsEveryClientAndCancellationTakesPrecedence(): void
    {
        $cancellation = new CanceledException('cleanup cancelled');
        $closed = [];
        $manager = app(ClientManager::class);

        foreach (['ordinary', 'cancelled', 'healthy'] as $name) {
            $manager->registerClient($name, function () use ($name, $cancellation, &$closed): Client {
                return new class(new FakeTransport, $name, $cancellation, $closed) extends Client {
                    /**
                     * Capture the teardown outcome for this test client.
                     */
                    public function __construct(FakeTransport $transport, private string $label, private CanceledException $cancellation, private array &$closed)
                    {
                        parent::__construct($transport);
                    }

                    /**
                     * Record teardown and raise the configured failure.
                     */
                    public function disconnect(): void
                    {
                        $this->closed[] = $this->label;
                        if ($this->label === 'ordinary') {
                            throw new RuntimeException('ordinary cleanup failure');
                        }
                        if ($this->label === 'cancelled') {
                            throw $this->cancellation;
                        }
                    }
                };
            });
            $manager->client($name);
        }

        try {
            $manager->disconnectAll();
            $this->fail('Cleanup cancellation must propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(['ordinary', 'cancelled', 'healthy'], $closed);
        $manager->disconnectAll();
        $this->assertCount(3, $closed);
    }
}

/**
 * Read the serialized transport configuration of a client.
 */
function transportRecipe(Client $client): array
{
    return (new ReflectionProperty(Client::class, 'transport'))->getValue($client)->recipe();
}

/**
 * Create a tool-list response with the supplied names.
 */
function listToolsResponse(int $id, array $names): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => ['tools' => array_map(fn (string $name): array => ['name' => $name], $names)],
    ]);
}
