<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\ResponseCache;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\TestCase;

use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;

/**
 * Create a cacheable resource response.
 */
function resourceResponse(string $text, string $resultType = 'complete'): string
{
    return (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'resultType' => $resultType,
            'ttlMs' => 300000,
            'cacheScope' => 'private',
            'contents' => [['uri' => 'file://a', 'mimeType' => 'text/plain', 'text' => $text]],
        ],
    ]);
}

/**
 * Create a tool page with cache metadata.
 */
function toolsPage(string $tool, ?string $nextCursor = null): string
{
    return (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => array_filter([
            'resultType' => 'complete',
            'ttlMs' => 300000,
            'cacheScope' => 'private',
            'tools' => [['name' => $tool]],
            'nextCursor' => $nextCursor,
        ]),
    ]);
}

/**
 * Create a transport with initialization and one cacheable tool response.
 */
function cacheableTransport(int $ttlMs = 300000, string $scope = 'private'): FakeTransport
{
    $transport = new FakeTransport;
    $transport->responses[] = initializeResponse();
    $transport->responses[] = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'resultType' => 'complete',
            'ttlMs' => $ttlMs,
            'cacheScope' => $scope,
            'tools' => [['name' => 'add']],
        ],
    ]);

    return $transport;
}

class CachingTest extends TestCase
{
    public function testServesAFreshResultWithoutTouchingTheTransportAgain(): void
    {
        $transport = cacheableTransport();
        $client = (new Client($transport))->withCache();

        $this->assertSame(['add'], $client->tools()->keys()->all());

        $sent = count($transport->sent);

        $this->assertSame(['add'], $client->tools()->keys()->all());
        $this->assertCount($sent, $transport->sent);
    }

    public function testRefetchesOnceTheTtlHasElapsed(): void
    {
        $transport = cacheableTransport(ttlMs: 1000);
        $client = (new Client($transport))->withCache();

        $client->tools();

        $sent = count($transport->sent);

        $this->travel(2)->seconds();
        $transport->responses[] = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['tools' => [['name' => 'add']]],
        ]);

        $client->tools();

        $this->assertGreaterThan($sent, count($transport->sent));
    }

    public function testNeverCachesAResultTheServerMarkedImmediatelyStale(): void
    {
        $transport = cacheableTransport(ttlMs: 0);
        $client = (new Client($transport))->withCache();

        $client->tools();

        $sent = count($transport->sent);

        $transport->responses[] = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['tools' => [['name' => 'add']]],
        ]);

        $client->tools();

        $this->assertGreaterThan($sent, count($transport->sent));
    }

    public function testKeepsPrivateResultsOutOfAnotherAuthorizationContext(): void
    {
        (new Client(cacheableTransport()))->withCache(for: 'tenant-a')->tools();

        $second = cacheableTransport();
        (new Client($second))->withCache(for: 'tenant-b')->tools();

        $this->assertNotEmpty($second->sent);
    }

    public function testSharesPublicResultsAcrossAuthorizationContexts(): void
    {
        (new Client(cacheableTransport(scope: 'public')))->withCache(for: 'tenant-a')->tools();

        $second = cacheableTransport(scope: 'public');
        $tools = (new Client($second))->withCache(for: 'tenant-b')->tools();

        $this->assertSame(['add'], $tools->keys()->all());
        $this->assertEmpty($second->sent);
    }

    public function testNeverResolvesTheTransportRecipeForAnUncacheableMethod(): void
    {
        $transport = new class extends FakeTransport {
            public int $recipes = 0;

            /**
             * Count recipe reads made during cache lookup.
             */
            public function recipe(): array
            {
                ++$this->recipes;

                return parent::recipe();
            }
        };

        $transport->responses[] = initializeResponse();
        $transport->responses[] = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => [['type' => 'text', 'text' => 'hi']], 'isError' => false],
        ]);

        (new Client($transport))->withCache()->callTool('say-hi');

        $this->assertSame(0, $transport->recipes);
    }

    public function testLeavesTheTransportUncachedUntilAsked(): void
    {
        $transport = cacheableTransport();
        $client = new Client($transport);

        $client->tools();

        $sent = count($transport->sent);

        $transport->responses[] = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['tools' => [['name' => 'add']]],
        ]);

        $client->tools();

        $this->assertGreaterThan($sent, count($transport->sent));
    }

    public function testNeverServesOneResourceForAnother(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = resourceResponse('alpha');
        $transport->responses[] = resourceResponse('bravo');

        $client = (new Client($transport))->withCache();

        $this->assertSame('alpha', $client->readResource('file://a')->content());
        $this->assertSame('bravo', $client->readResource('file://b')->content());
    }

    public function testNeverCachesAnInterimResult(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = resourceResponse('alpha', resultType: 'input_required');
        $transport->responses[] = resourceResponse('bravo');

        $client = (new Client($transport))->withCache();

        $this->assertSame('alpha', $client->readResource('file://a')->content());
        $this->assertSame('bravo', $client->readResource('file://a')->content());
    }

    public function testNeverCachesAPageOfAPaginatedList(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = toolsPage('add', nextCursor: 'page-2');
        $transport->responses[] = toolsPage('subtract');
        $transport->responses[] = toolsPage('add', nextCursor: 'page-2');
        $transport->responses[] = toolsPage('subtract');

        $client = (new Client($transport))->withCache();

        $this->assertSame(['add', 'subtract'], $client->tools()->keys()->all());

        $sent = count($transport->sent);

        $this->assertSame(['add', 'subtract'], $client->tools()->keys()->all());
        $this->assertGreaterThan($sent, count($transport->sent));
    }

    public function testStopsCachingOnceTheCacheIsTurnedBackOff(): void
    {
        $transport = cacheableTransport();
        $client = (new Client($transport))->withCache()->withoutCache();

        $client->tools();

        $sent = count($transport->sent);

        $transport->responses[] = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['tools' => [['name' => 'add']]],
        ]);

        $client->tools();

        $this->assertGreaterThan($sent, count($transport->sent));
    }

    public function testKeepsCachingThroughASerializeRoundTrip(): void
    {
        $client = Client::local('node', ['server.js'])->withCache(store: 'array', for: 'tenant-a');

        $restored = unserialize(serialize($client));

        $cache = $restored->__serialize()['cache'];
        $this->assertInstanceOf(ResponseCache::class, $cache);
        $this->assertSame('array', $cache->store);
        $this->assertSame('tenant-a', $cache->for);
    }
}
