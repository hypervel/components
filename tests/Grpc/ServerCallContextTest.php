<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Server\CallContextStore;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use LogicException;

use function Hypervel\Coroutine\parallel;

class ServerCallContextTest extends TestCase
{
    public function testExposesCallValuesAndUsesTheMonotonicDeadline(): void
    {
        $now = 1_000_000_000;
        $deadline = Deadline::usingClock(3_500_000_000, static function () use (&$now): int {
            return $now;
        });
        $wallClockDeadline = CarbonImmutable::parse('2026-07-20 12:00:02.500000', 'UTC');
        $metadata = Metadata::make(['x-request-id' => 'request-1']);
        $context = new ServerCallContext(
            $metadata,
            'helloworld.Greeter',
            'SayHello',
            '127.0.0.1:50000',
            $wallClockDeadline,
            $deadline,
            2,
        );

        $this->assertSame($metadata, $context->metadata());
        $this->assertSame('helloworld.Greeter', $context->service());
        $this->assertSame('SayHello', $context->method());
        $this->assertSame('127.0.0.1:50000', $context->peer());
        $this->assertSame($wallClockDeadline, $context->deadline());
        $this->assertSame(2.5, $context->timeRemaining());
        $this->assertFalse($context->deadlineExceeded());
        $this->assertSame(2, $context->previousAttempts());

        $now = 3_500_000_000;

        $this->assertSame(0.0, $context->timeRemaining());
        $this->assertTrue($context->deadlineExceeded());
    }

    public function testExposesAnAbsentDeadline(): void
    {
        $context = $this->context('without-deadline', Deadline::usingClock(null, static fn (): int => 1));

        $this->assertNull($context->deadline());
        $this->assertNull($context->timeRemaining());
        $this->assertFalse($context->deadlineExceeded());
    }

    public function testStoreIsIsolatedBetweenConcurrentCoroutines(): void
    {
        $store = new CallContextStore;

        [$first, $second] = parallel([
            function () use ($store): string {
                $store->set($this->context('first'));
                usleep(5_000);

                return $store->get()->method();
            },
            function () use ($store): string {
                $store->set($this->context('second'));
                usleep(5_000);

                return $store->get()->method();
            },
        ]);

        $this->assertSame('first', $first);
        $this->assertSame('second', $second);
    }

    public function testStoreForgetsTheCurrentContext(): void
    {
        $store = new CallContextStore;
        $store->set($this->context('stored'));

        $this->assertSame('stored', $store->get()->method());

        $store->forget();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No gRPC server call is active in the current coroutine.');

        $store->get();
    }

    private function context(string $method, ?Deadline $deadline = null): ServerCallContext
    {
        return new ServerCallContext(
            Metadata::make(),
            'hypervel.grpc.testing.TestService',
            $method,
            '[::1]:50000',
            null,
            $deadline ?? Deadline::usingClock(null, static fn (): int => 1),
            0,
        );
    }
}
