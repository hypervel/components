<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Closure;
use Hypervel\Grpc\Server\GrpcStreamedResponse;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class GrpcStreamedResponseTest extends TestCase
{
    public function testStreamsRetainedChunksResolvesTrailersAndCompletesExactlyOnce(): void
    {
        $producerReleased = 0;
        $completionCount = 0;
        $trailerResolutionCount = 0;
        $chunks = static function () use (&$producerReleased): iterable {
            try {
                yield 'first';
                yield 'second';
            } finally {
                ++$producerReleased;
            }
        };
        $response = $this->response(
            $chunks(),
            function () use (&$trailerResolutionCount): array {
                ++$trailerResolutionCount;

                return ['grpc-status' => '0'];
            },
        );
        $response->completeUsing(static function () use (&$completionCount): void {
            ++$completionCount;
        });
        $written = [];

        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $this->assertTrue($response->streamTo(static function (string $chunk) use (&$written): bool {
            $written[] = $chunk;

            return true;
        }));
        $this->assertSame(['first', 'second'], $written);
        $this->assertSame(1, $producerReleased);
        $this->assertSame(1, $completionCount);
        $this->assertSame(['grpc-status'], $response->trailerNames());
        $this->assertSame(['grpc-status' => '0'], $response->trailers());
        $this->assertSame(1, $trailerResolutionCount);

        $response->complete();
        $this->assertSame(1, $completionCount);
    }

    public function testWriterFalseStopsAndReleasesTheProducerBeforeLaterChunksRun(): void
    {
        $afterFirstYield = false;
        $producerReleased = 0;
        $completionCount = 0;
        $chunks = static function () use (&$afterFirstYield, &$producerReleased): iterable {
            try {
                yield 'first';
                $afterFirstYield = true;
                yield 'second';
            } finally {
                ++$producerReleased;
            }
        };
        $response = $this->response($chunks());
        $response->completeUsing(static function () use (&$completionCount): void {
            ++$completionCount;
        });

        $this->assertTrue($response->streamTo(static fn (): bool => false));
        $this->assertFalse($afterFirstYield);
        $this->assertSame(1, $producerReleased);
        $this->assertSame(1, $completionCount);
    }

    public function testWriterExceptionReleasesTheProducerAndPreservesTheTransportFailure(): void
    {
        $failure = new RuntimeException('transport failed');
        $producerReleased = 0;
        $completionCount = 0;
        $chunks = static function () use (&$producerReleased): iterable {
            try {
                yield 'first';
                yield 'second';
            } finally {
                ++$producerReleased;
            }
        };
        $response = $this->response($chunks());
        $response->completeUsing(static function () use (&$completionCount): void {
            ++$completionCount;
        });

        try {
            $response->streamTo(static function () use ($failure): never {
                throw $failure;
            });
            $this->fail('Expected the transport failure to escape stream production.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $producerReleased);
        $this->assertSame(1, $completionCount);
    }

    #[DataProvider('protocolMutations')]
    public function testDetectsMiddlewareMutationOfProtocolOwnedState(Closure $mutate): void
    {
        $response = $this->response(['chunk']);
        $this->assertTrue($response->protocolStateIsIntact());

        $mutate($response);

        $this->assertFalse($response->protocolStateIsIntact());
    }

    /**
     * Return protocol-owned response mutations.
     *
     * @return iterable<string, array{Closure(GrpcStreamedResponse): void}>
     */
    public static function protocolMutations(): iterable
    {
        yield 'status' => [static function (GrpcStreamedResponse $response): void {
            $response->setStatusCode(201);
        }];
        yield 'headers' => [static function (GrpcStreamedResponse $response): void {
            $response->headers->set('x-injected', 'middleware');
        }];
        yield 'chunks' => [static function (GrpcStreamedResponse $response): void {
            $response->setChunks(['replacement']);
        }];
        yield 'callback' => [static function (GrpcStreamedResponse $response): void {
            $response->setCallback(static function (): void {
            });
        }];
    }

    /**
     * Build a protocol-owned streamed response fixture.
     *
     * @param iterable<string> $chunks
     * @param null|Closure(): array<string, string> $resolveTrailers
     */
    private function response(iterable $chunks, ?Closure $resolveTrailers = null): GrpcStreamedResponse
    {
        return new GrpcStreamedResponse(
            $chunks,
            ['content-type' => 'application/grpc+proto'],
            ['grpc-status'],
            $resolveTrailers ?? static fn (): array => ['grpc-status' => '0'],
        );
    }
}
