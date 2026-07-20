<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Generator;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\StreamedEvent;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseFactoryTest extends TestCase
{
    public function testStreamRetainsGeneratorChunksLazily(): void
    {
        $iterations = 0;
        $response = $this->factory()->stream(function () use (&$iterations) {
            ++$iterations;

            yield 'first';
            yield 'second';
        });

        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame(0, $iterations);
        $chunks = [];

        $this->assertTrue($response->streamTo(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        }));
        $this->assertSame(['first', 'second'], $chunks);
        $this->assertSame(1, $iterations);
    }

    public function testStreamSupportsArrayGeneratorCallables(): void
    {
        $source = new ResponseFactoryGeneratorSource;
        $response = $this->factory()->stream([$source, 'generate']);
        $chunks = [];

        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertTrue($response->streamTo(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        }));
        $this->assertSame(['array'], $chunks);
    }

    public function testStreamSupportsInvokableGeneratorCallables(): void
    {
        $response = $this->factory()->stream(new ResponseFactoryGeneratorSource);
        $chunks = [];

        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertTrue($response->streamTo(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        }));
        $this->assertSame(['invokable'], $chunks);
    }

    public function testStreamKeepsOrdinaryCallbacksOnSymfonyResponse(): void
    {
        $response = $this->factory()->stream(static function (): void {
            echo 'callback';
        });

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertNotInstanceOf(IterableStreamedResponse::class, $response);
    }

    public function testEventStreamStopsProducingAfterAFailedWrite(): void
    {
        $produced = 0;
        $response = $this->factory()->eventStream(function () use (&$produced) {
            ++$produced;
            yield 'first';
            ++$produced;
            yield 'second';
        });
        $chunks = [];

        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertTrue($response->streamTo(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return false;
        }));
        $this->assertSame(["event: update\ndata: first\n\n"], $chunks);
        $this->assertSame(1, $produced);
    }

    public function testEventStreamYieldsCompleteMessageAndTerminalEventChunks(): void
    {
        $response = $this->factory()->eventStream(
            static function () {
                yield 'first';
                yield new StreamedEvent('custom', ['value' => true]);
            },
            endStreamWith: new StreamedEvent('complete', ['finished' => true]),
        );
        $chunks = [];

        $this->assertTrue($response->streamTo(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        }));
        $this->assertSame([
            "event: update\ndata: first\n\n",
            "event: custom\ndata: {\"value\":true}\n\n",
            "event: complete\ndata: {\"finished\":true}\n\n",
        ], $chunks);
    }

    public function testEventStreamPrefixesEveryMultilineDataField(): void
    {
        $response = $this->factory()->eventStream(
            static function () {
                yield "first\r\nsecond\rthird\nfourth";
            },
            endStreamWith: null,
        );
        $chunks = [];

        $this->assertTrue($response->streamTo(function (string $chunk) use (&$chunks): bool {
            $chunks[] = $chunk;

            return true;
        }));
        $this->assertSame([
            "event: update\ndata: first\ndata: second\ndata: third\ndata: fourth\n\n",
        ], $chunks);
    }

    private function factory(): ResponseFactory
    {
        return new ResponseFactory(
            m::mock(ViewFactory::class),
            m::mock(Redirector::class),
        );
    }
}

class ResponseFactoryGeneratorSource
{
    public function generate(): Generator
    {
        yield 'array';
    }

    public function __invoke(): Generator
    {
        yield 'invokable';
    }
}
