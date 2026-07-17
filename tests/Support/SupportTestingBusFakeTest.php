<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Support\Testing\Fakes\BusFake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;

class SupportTestingBusFakeTest extends TestCase
{
    public function testAssertDispatchedOnce(): void
    {
        $fake = new BusFake(m::mock(QueueingDispatcher::class));

        $fake->dispatch(new BusFakeJobStub);
        $fake->assertDispatchedOnce(BusFakeJobStub::class);

        $fake->dispatchNow(new BusFakeJobStub);

        try {
            $fake->assertDispatchedOnce(BusFakeJobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString(
                'The expected [' . BusFakeJobStub::class . '] job was pushed 2 times instead of 1 time.',
                $exception->getMessage()
            );
        }
    }

    #[DataProvider('countAssertionMethods')]
    public function testCountAssertionsPluralizeFailureMessages(
        string $dispatchMethod,
        string $assertionMethod,
        string $action
    ): void {
        $fake = new BusFake(m::mock(QueueingDispatcher::class));
        $fake->{$dispatchMethod}(new BusFakeJobStub);

        try {
            $fake->{$assertionMethod}(BusFakeJobStub::class, 2);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString(
                'The expected [' . BusFakeJobStub::class . "] {$action} 1 time instead of 2 times.",
                $exception->getMessage()
            );
        }
    }

    public static function countAssertionMethods(): array
    {
        return [
            ['dispatch', 'assertDispatchedTimes', 'job was pushed'],
            ['dispatchSync', 'assertDispatchedSyncTimes', 'job was synchronously pushed'],
            ['dispatchAfterResponse', 'assertDispatchedAfterResponseTimes', 'job was pushed'],
        ];
    }
}

class BusFakeJobStub
{
}
