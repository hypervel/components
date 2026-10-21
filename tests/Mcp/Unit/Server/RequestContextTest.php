<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server;

use Generator;
use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server\RequestContext;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class RequestContextTest extends TestCase
{
    public function testPreservesEagerArraysAndRestoresNestedContextAfterFailure(): void
    {
        $outer = new Request(['name' => 'outer']);
        $inner = new Request(['name' => 'inner']);
        $failure = new RuntimeException('Failed handler');

        $result = RequestContext::run($outer, function () use ($outer, $inner, $failure): array {
            CoroutineContext::set(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY, 'outer scripts');

            try {
                RequestContext::run($inner, function () use ($inner, $failure): never {
                    $this->assertSame($inner, CoroutineContext::get(Request::class));
                    $this->assertFalse(CoroutineContext::has(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY));
                    throw $failure;
                });
                $this->fail('The handler exception must propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertSame($outer, CoroutineContext::get(Request::class));
            $this->assertSame('outer scripts', CoroutineContext::get(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY));

            return ['content' => 'result'];
        });

        $this->assertSame(['content' => 'result'], $result);
        $this->assertFalse(CoroutineContext::has(Request::class));
        $this->assertFalse(CoroutineContext::has(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY));
    }

    #[DataProvider('cleanupOutcomes')]
    public function testAbandonedGeneratorClosesUnderItsOwnRequestAndRestoresConsumerContext(bool $throws): void
    {
        $request = new Request(['name' => 'resource']);
        $consumer = new Request(['name' => 'consumer']);
        $closedUnder = null;
        $failure = $throws ? new RuntimeException('Cleanup failed') : null;

        $response = RequestContext::run($request, function () use (&$closedUnder, $failure): Generator {
            CoroutineContext::set(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY, 'resource scripts');

            return (function () use (&$closedUnder, $failure): Generator {
                try {
                    yield [
                        CoroutineContext::get(Request::class),
                        CoroutineContext::get(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY),
                    ];
                } finally {
                    $closedUnder = CoroutineContext::get(Request::class);

                    if ($failure !== null) {
                        throw $failure;
                    }
                }
            })();
        });

        $this->assertFalse(CoroutineContext::has(Request::class));
        CoroutineContext::set(Request::class, $consumer);
        CoroutineContext::set(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY, 'consumer scripts');

        $this->assertSame([$request, 'resource scripts'], $response->current());
        $caught = null;
        try {
            unset($response);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame($request, $closedUnder);
        $this->assertSame($consumer, CoroutineContext::get(Request::class));
        $this->assertSame('consumer scripts', CoroutineContext::get(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY));
    }

    /**
     * Supply successful and throwing handler cleanup.
     */
    public static function cleanupOutcomes(): array
    {
        return [[false], [true]];
    }

    public function testConcurrentLazyResponsesKeepTheirOwnRequests(): void
    {
        $responses = parallel([
            fn (): array => $this->consume('first'),
            fn (): array => $this->consume('second'),
        ]);

        $this->assertSame([['first'], ['second']], $responses);
    }

    /**
     * Consume a handler while allowing another invocation to run.
     */
    private function consume(string $name): array
    {
        $response = RequestContext::run(new Request(['name' => $name]), function (): Generator {
            usleep(5000);
            yield CoroutineContext::get(Request::class)->get('name');
        });

        return iterator_to_array($response);
    }
}
