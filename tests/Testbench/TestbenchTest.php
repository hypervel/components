<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Queue\Queue;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Concerns\Testing;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Jobs\CustomPayloadJob;

class TestbenchTest extends TestCase
{
    #[Test]
    public function itCanResolveUsesTestingConcerns(): void
    {
        $this->assertTrue(static::usesTestingConcern(Testing::class));
        $this->assertFalse(static::usesTestingConcern(\Hypervel\Testbench\Concerns\WithWorkbench::class));
    }

    #[Test]
    #[DefineEnvironment('registerCustomQueuePayload')]
    public function itCanHandleCustomQueuePayload(): void
    {
        $dispatcher = $this->app->make(QueueingDispatcher::class);

        $dispatcher->dispatchToQueue(new CustomPayloadJob);

        $this->addToAssertionCount(1);
    }

    protected function registerCustomQueuePayload(\Hypervel\Contracts\Foundation\Application $app): void
    {
        $app->bind('one.time.password', fn (): int => random_int(1, 10));

        Queue::createPayloadUsing(function () use ($app): array {
            $password = $app->make('one.time.password');

            $app->offsetUnset('one.time.password');

            return ['password' => $password];
        });
    }
}
