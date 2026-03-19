<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Mail\Mailable as MailableContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
use Hypervel\View\Factory;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * @internal
 * @coversNothing
 */
class MailableQueuedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MailableContract::class, m::mock(MailableContract::class));
    }

    public function testQueuedMailableSent()
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub();
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableWithAttachmentSent()
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub();
        $attachmentOption = ['mime' => 'image/jpeg', 'as' => 'bar.jpg'];
        $mailable->attach('foo.jpg', $attachmentOption);
        $this->assertIsArray($mailable->attachments);
        $this->assertCount(1, $mailable->attachments);
        $this->assertEquals($mailable->attachments[0]['options'], $attachmentOption);
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableReceivesMailableInstance()
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub();
        $mailer->send($mailable);

        $queueFake->assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) use ($mailable) {
            return $job->mailable === $mailable;
        });
    }

    public function testQueuedMailableWithAttachmentFromDiskSent()
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub();
        $attachmentOption = ['mime' => 'image/jpeg', 'as' => 'bar.jpg'];

        $mailable->attachFromStorage('/', 'foo.jpg', $attachmentOption);

        $this->assertIsArray($mailable->diskAttachments);
        $this->assertCount(1, $mailable->diskAttachments);
        $this->assertEquals($mailable->diskAttachments[0]['options'], $attachmentOption);

        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableForwardsMessageGroupFromMethodToQueueJob()
    {
        $mockedMessageGroupId = 'group-1';

        $mailable = $this->getMockBuilder(MailableQueueableStubWithMessageGroup::class)->onlyMethods(['messageGroup'])->getMock();
        $mailable->expects($this->once())->method('messageGroup')->willReturn($mockedMessageGroupId);

        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertEquals($mockedMessageGroupId, $pushedJob->messageGroup);
    }

    public function testQueuedMailableForwardsMessageGroupFromPropertyOverridingMethodToQueueJob()
    {
        $mockedMessageGroupId = 'group-1';

        // Ensure the messageGroup method is not called when a messageGroup property is provided.
        $mailable = $this->getMockBuilder(MailableQueueableStubWithMessageGroup::class)->onlyMethods(['messageGroup'])->getMock();
        $mailable->expects($this->never())->method('messageGroup')->willReturn('this-should-not-be-used');
        $mailable->onGroup($mockedMessageGroupId);

        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertEquals($mockedMessageGroupId, $pushedJob->messageGroup);
    }

    public function testQueuedMailableForwardsDeduplicatorToQueueJob()
    {
        $mockedDeduplicator = fn ($payload, $queue) => 'deduplication-id-1';

        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = (new MailableQueueableStub())->withDeduplicator($mockedDeduplicator);
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertInstanceOf(SerializableClosure::class, $pushedJob->deduplicator);
        $this->assertEquals($mockedDeduplicator, $pushedJob->deduplicator->getClosure());
    }

    public function testQueuedMailableForwardsDeduplicationIdMethodToQueueJob()
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStubWithDeduplication();
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertInstanceOf(SerializableClosure::class, $pushedJob->deduplicator);
        $this->assertEquals($mailable->deduplicationId(...), $pushedJob->deduplicator->getClosure());
    }

    protected function getMocks()
    {
        return ['smtp', m::mock(Factory::class), m::mock(TransportInterface::class)];
    }

    protected function createMailer(QueueFake $queueFake): Mailer
    {
        return (new Mailer(...$this->getMocks()))->setQueue($queueFake);
    }
}

class MailableQueueableStub extends Mailable implements ShouldQueue
{
    use Queueable;

    public function build(): static
    {
        $this->subject('lorem ipsum')
            ->html('foo bar baz')
            ->to('foo@example.tld');

        return $this;
    }
}

class MailableQueueableStubWithMessageGroup extends Mailable implements ShouldQueue
{
    use Queueable;

    public function build(): static
    {
        $this->subject('lorem ipsum')
            ->html('foo bar baz')
            ->to('foo@example.tld');

        return $this;
    }

    public function messageGroup(): string
    {
        return 'group-1';
    }
}

class MailableQueueableStubWithDeduplication extends Mailable implements ShouldQueue
{
    use Queueable;

    public function build(): static
    {
        $this->subject('lorem ipsum')
            ->html('foo bar baz')
            ->to('foo@example.tld');

        return $this;
    }

    public function deduplicationId($payload, $queue)
    {
        return hash('sha256', $payload);
    }
}
