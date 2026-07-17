<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\MailManager;
use Hypervel\Support\Testing\Fakes\MailFake;
use Hypervel\Tests\TestCase;
use Mockery as m;

class SupportTestingMailFakeTest extends TestCase
{
    public function testSendNowSendsQueueableMailableSynchronouslyWithPendingRecipients(): void
    {
        $fake = $this->mailFake();

        $fake->to('taylor@hypervel.org')->sendNow(new QueueableMailableStub);

        $fake->assertSent(
            QueueableMailableStub::class,
            fn (QueueableMailableStub $mailable): bool => $mailable->hasTo('taylor@hypervel.org')
        );
        $fake->assertNotQueued(QueueableMailableStub::class);
    }

    public function testAssertQueuedTimesCanBeCalledDirectly(): void
    {
        $fake = $this->mailFake();

        $fake->queue(new Mailable);
        $fake->queue(new Mailable);

        $fake->assertQueuedTimes(Mailable::class, 2);
    }

    private function mailFake(): MailFake
    {
        $manager = m::mock(MailManager::class);
        $manager->shouldReceive('getDefaultDriver')->once()->andReturn('smtp');

        return new MailFake($manager);
    }
}

class QueueableMailableStub extends Mailable implements ShouldQueue
{
}
