<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server\CachingTest;

use Closure;
use Hypervel\Mcp\Enums\CacheScope;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Attributes\Cacheable;
use Hypervel\Tests\Mcp\Fixtures\ArrayTransport;
use Hypervel\Tests\Mcp\Fixtures\CacheableResource;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Mcp\Fixtures\callToolMessage;
use function Hypervel\Tests\Mcp\Fixtures\listToolsMessage;
use function Hypervel\Tests\Mcp\Fixtures\protocolMeta;
use function Hypervel\Tests\Mcp\Fixtures\readResourceMessage;

/**
 * Dispatch a message and decode its first response.
 */
function resultFor(array $message, ?Closure $factory = null): array
{
    $transport = new ArrayTransport;
    $server = $factory instanceof Closure ? $factory($transport) : new ExampleServer($transport);

    $server->start();

    ($transport->handler)((string) json_encode($message));

    return json_decode((string) $transport->sent[0], true);
}

class CachingTest extends TestCase
{
    #[DataProvider('cacheableMethods')]
    public function testAddsCachingHintsToEveryCacheableResult(string $method, array $params): void
    {
        $result = resultFor([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => ['_meta' => protocolMeta(), ...$params],
        ])['result'];

        $this->assertSame(0, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
    }

    /**
     * Provide cacheable protocol requests.
     */
    public static function cacheableMethods(): array
    {
        return [
            'server/discover' => ['server/discover', []],
            'tools/list' => ['tools/list', []],
            'prompts/list' => ['prompts/list', []],
            'resources/list' => ['resources/list', []],
            'resources/templates/list' => ['resources/templates/list', []],
            'resources/read' => ['resources/read', ['uri' => 'file://resources/last-log-line-resource']],
        ];
    }

    public function testLeavesCachingHintsOffAResultThatIsNotCacheable(): void
    {
        $result = resultFor(callToolMessage())['result'];

        $this->assertArrayNotHasKey('ttlMs', $result);
        $this->assertArrayNotHasKey('cacheScope', $result);
    }

    #[DataProvider('retryParameters')]
    public function testLeavesCachingHintsOffARequestRetriedWithInputResponses(array $params): void
    {
        $result = resultFor([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => [
                '_meta' => protocolMeta(),
                'uri' => 'file://resources/last-log-line-resource',
                ...$params,
            ],
        ])['result'];

        $this->assertArrayNotHasKey('ttlMs', $result);
        $this->assertArrayNotHasKey('cacheScope', $result);
    }

    /**
     * Provide continuation parameters that disable caching.
     */
    public static function retryParameters(): array
    {
        return [
            'inputResponses' => [['inputResponses' => ['login' => ['action' => 'accept']]]],
            'requestState' => [['requestState' => 'eyJ...']],
        ];
    }

    public function testUsesTheHintTheServerAttributeDeclares(): void
    {
        $result = resultFor(listToolsMessage(), fn (ArrayTransport $transport): Server => new #[Cacheable(ttlMs: 300000, scope: CacheScope::Public)] class($transport) extends ExampleServer {})['result'];

        $this->assertSame(300000, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);
    }

    public function testPrefersThePerMethodHintOverTheServerAttribute(): void
    {
        $server = fn (ArrayTransport $transport): Server => new #[Cacheable(ttlMs: 300000, scope: CacheScope::Public)] class($transport) extends ExampleServer {
            /**
             * Define method-specific cache hints.
             */
            protected function cacheHints(): array
            {
                return ['tools/list' => new Cacheable(ttlMs: 60000)];
            }
        };

        $result = resultFor(listToolsMessage(), $server)['result'];
        $this->assertSame(60000, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);

        $listPrompts = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list', 'params' => ['_meta' => protocolMeta()]];

        $result = resultFor($listPrompts, $server)['result'];
        $this->assertSame(300000, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);
    }

    public function testPrefersTheResourceAttributeOverTheServerHints(): void
    {
        $server = function (ArrayTransport $transport): Server {
            $server = new #[Cacheable(ttlMs: 300000)] class($transport) extends ExampleServer {
                /**
                 * Define method-specific cache hints.
                 */
                protected function cacheHints(): array
                {
                    return ['resources/read' => new Cacheable(ttlMs: 60000)];
                }
            };

            $server->resources[] = CacheableResource::class;

            return $server;
        };

        $result = resultFor(readResourceMessage('file://resources/cacheable-resource'), $server)['result'];
        $this->assertSame(120000, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);

        $result = resultFor(readResourceMessage('file://resources/last-log-line-resource'), $server)['result'];
        $this->assertSame(60000, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
    }

    public function testNeverEmitsANegativeTtl(): void
    {
        $result = resultFor(listToolsMessage(), fn (ArrayTransport $transport): Server => new #[Cacheable(ttlMs: -1)] class($transport) extends ExampleServer {})['result'];

        $this->assertSame(0, $result['ttlMs']);
    }

    public function testReturnsToolsInTheSameOrderOnEveryRequest(): void
    {
        $names = fn (): array => array_column(resultFor(listToolsMessage())['result']['tools'], 'name');

        $this->assertSame($names(), $names());
        $this->assertNotEmpty($names());
    }
}
