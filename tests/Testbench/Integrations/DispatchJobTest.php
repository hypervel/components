<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Testing\Fakes\BusFake;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Jobs\RegisterUser;

class DispatchJobTest extends TestCase
{
    #[Test]
    public function itCanTriggerExpectedJobs(): void
    {
        Bus::fake();

        dispatch(new RegisterUser);

        Bus::assertDispatched(RegisterUser::class);
    }

    #[Test]
    public function itResolvesTheQueueingDispatcherToTheBusFake(): void
    {
        $fake = Bus::fake();

        $this->assertInstanceOf(BusFake::class, $fake);
        $this->assertSame($fake, $this->app->make(QueueingDispatcher::class));
    }
}
