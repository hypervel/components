<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Mail\Mailable as MailableContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
use Hypervel\View\Factory;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use Symfony\Component\Mailer\Transport\TransportInterface;

class MailableQueuedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MailableContract::class, m::mock(MailableContract::class));
    }

    public function testQueuedMailableSent(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub;
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableWithAttachmentSent(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub;
        $attachmentOption = ['mime' => 'image/jpeg', 'as' => 'bar.jpg'];
        $mailable->attach('foo.jpg', $attachmentOption);
        $this->assertIsArray($mailable->attachments);
        $this->assertCount(1, $mailable->attachments);
        $this->assertEquals($mailable->attachments[0]['options'], $attachmentOption);
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableReceivesMailableInstance(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub;
        $mailer->send($mailable);

        $queueFake->assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) use ($mailable): bool {
            return $job->mailable === $mailable;
        });
    }

    public function testQueuedMailableWithAttachmentFromDiskSent(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStub;
        $attachmentOption = ['mime' => 'image/jpeg', 'as' => 'bar.jpg'];

        $mailable->attachFromStorage('/', 'foo.jpg', $attachmentOption);

        $this->assertIsArray($mailable->diskAttachments);
        $this->assertCount(1, $mailable->diskAttachments);
        $this->assertEquals($mailable->diskAttachments[0]['options'], $attachmentOption);

        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableForwardsMessageGroupFromMethodToQueueJob(): void
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

    public function testQueuedMailableForwardsMessageGroupFromPropertyOverridingMethodToQueueJob(): void
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

    public function testQueuedMailableForwardsDeduplicatorToQueueJob(): void
    {
        $mockedDeduplicator = fn ($payload, $queue) => 'deduplication-id-1';

        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = (new MailableQueueableStub)->withDeduplicator($mockedDeduplicator);
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertInstanceOf(SerializableClosure::class, $pushedJob->deduplicator);
        $this->assertEquals($mockedDeduplicator, $pushedJob->deduplicator->getClosure());
    }

    public function testQueuedMailableRespectsDelayAttribute(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStubWithDelayAttribute;
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertSame(30, $pushedJob->delay);
    }

    public function testQueuedMailableDelayPropertyOverridesAttribute(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStubWithDelayAttribute;
        $mailable->delay = 60;
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertSame(60, $pushedJob->delay);
    }

    public function testQueuedMailableRespectsQueueAndConnectionAttributes(): void
    {
        $queueFake = new MailableQueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStubWithQueueAndConnectionAttributes;
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn('mail-queue', SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertSame('redis', $queueFake->connectionName);
        $this->assertSame('mail-queue', $pushedJob->queue);
        $this->assertSame('redis', $pushedJob->connection);
    }

    public function testDelayedQueuedMailableRespectsQueueAndConnectionAttributes(): void
    {
        $queueFake = new MailableQueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStubWithDelayQueueAndConnectionAttributes;
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn('delayed-mail-queue', SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertSame('sqs', $queueFake->connectionName);
        $this->assertSame('delayed-mail-queue', $pushedJob->queue);
        $this->assertSame('sqs', $pushedJob->connection);
        $this->assertSame(30, $pushedJob->delay);
    }

    public function testQueuedMailablePreservesZeroQueueAndDefaultsEmptyQueue(): void
    {
        $zeroQueue = new QueueFake($this->app);
        (new MailableQueueableStub)->onQueue('0')->queue($zeroQueue);

        $zeroQueue->assertPushedOn('0', SendQueuedMailable::class);

        $defaultQueue = new QueueFake($this->app);
        (new MailableQueueableStub)->onQueue('')->queue($defaultQueue);

        $defaultQueue->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testDelayedMailablePreservesZeroQueueAndDefaultsEmptyQueue(): void
    {
        $zeroQueue = new QueueFake($this->app);
        (new MailableQueueableStub)->onQueue('0')->later(10, $zeroQueue);

        $zeroQueue->assertPushedOn('0', SendQueuedMailable::class);

        $defaultQueue = new QueueFake($this->app);
        (new MailableQueueableStub)->onQueue('')->later(10, $defaultQueue);

        $defaultQueue->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableAcceptsImmutableRetryUntilProperty(): void
    {
        $retryUntil = CarbonImmutable::parse('2026-07-23 12:34:56');
        $mailable = new MailableQueueableStubWithRetryUntil;
        $mailable->retryUntil = $retryUntil;

        $this->assertSame(
            $retryUntil,
            (new SendQueuedMailable($mailable))->retryUntil()
        );
    }

    public function testQueuedMailableForwardsDeduplicationIdMethodToQueueJob(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);
        $mailable = new MailableQueueableStubWithDeduplication;
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);

        $pushedJob = $queueFake->pushed(SendQueuedMailable::class)->first();
        $this->assertInstanceOf(SerializableClosure::class, $pushedJob->deduplicator);

        $deduplicator = $pushedJob->deduplicator->getClosure();
        $this->assertSame(
            $mailable->deduplicationId('payload', 'queue'),
            $deduplicator('payload', 'queue')
        );
    }

    public function testLaterSetsQueueOnMailable(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);

        $mailer->later(60, new MailableQueueableStub, 'emails');

        $queueFake->assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job): bool {
            return $job->queue === 'emails';
        });
    }

    public function testLaterWithoutQueueUsesDefault(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);

        $mailer->later(60, new MailableQueueableStub);

        $queueFake->assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job): bool {
            return $job->queue === null;
        });
    }

    public function testQueueMethodsPreserveStringAndEnumQueueNames(): void
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->createMailer($queueFake);

        $mailer->queue(new MailableQueueableStub, 'queue-string');
        $mailer->queue(new MailableQueueableStub, MailableQueueName::Transactional);
        $mailer->onQueue('on-queue', new MailableQueueableStub);
        $mailer->onQueue(MailableQueueName::Transactional, new MailableQueueableStub);
        $mailer->queueOn('queue-on', new MailableQueueableStub);
        $mailer->queueOn(MailableQueueName::Transactional, new MailableQueueableStub);
        $mailer->later(60, new MailableQueueableStub, 'later-string');
        $mailer->later(60, new MailableQueueableStub, MailableQueueName::Transactional);
        $mailer->laterOn('later-on', 60, new MailableQueueableStub);
        $mailer->laterOn(MailableQueueName::Transactional, 60, new MailableQueueableStub);

        $this->assertSame(
            [
                'queue-string',
                'transactional-mail',
                'on-queue',
                'transactional-mail',
                'queue-on',
                'transactional-mail',
                'later-string',
                'transactional-mail',
                'later-on',
                'transactional-mail',
            ],
            $queueFake->pushed(SendQueuedMailable::class)
                ->map(fn (SendQueuedMailable $job): ?string => $job->queue)
                ->all()
        );
    }

    protected function getMocks(): array
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

#[Delay(30)]
class MailableQueueableStubWithDelayAttribute extends Mailable implements ShouldQueue
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

    public function deduplicationId(string $payload, string $queue): string
    {
        return hash('sha256', $payload);
    }
}

class MailableQueueableStubWithRetryUntil extends MailableQueueableStub
{
    public CarbonImmutable $retryUntil;
}

#[Connection('redis')]
#[QueueAttribute('mail-queue')]
class MailableQueueableStubWithQueueAndConnectionAttributes extends MailableQueueableStub
{
}

#[Connection('sqs')]
#[Delay(30)]
#[QueueAttribute('delayed-mail-queue')]
class MailableQueueableStubWithDelayQueueAndConnectionAttributes extends MailableQueueableStub
{
}

class MailableQueueFake extends QueueFake
{
    public ?string $connectionName = null;

    public function connection(mixed $value = null): QueueContract
    {
        $this->connectionName = $value;

        return parent::connection($value);
    }
}

enum MailableQueueName: string
{
    case Transactional = 'transactional-mail';
}
