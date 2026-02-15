<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\View\Factory;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Mail\Mailable as MailableContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
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
        $mailer = $this->getMockBuilder(Mailer::class)
            ->setConstructorArgs($this->getMocks())
            ->onlyMethods(['createMessage', 'to'])
            ->getMock();
        $mailer->setQueue($queueFake);
        $mailable = new MailableQueueableStub();
        $queueFake->assertNothingPushed();
        $mailer->send($mailable);
        $queueFake->assertPushedOn(null, SendQueuedMailable::class);
    }

    public function testQueuedMailableWithAttachmentSent()
    {
        $queueFake = new QueueFake($this->app);
        $mailer = $this->getMockBuilder(Mailer::class)
            ->setConstructorArgs($this->getMocks())
            ->onlyMethods(['createMessage'])
            ->getMock();
        $mailer->setQueue($queueFake);
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
        $mailer = $this->getMockBuilder(Mailer::class)
            ->setConstructorArgs($this->getMocks())
            ->onlyMethods(['createMessage', 'to'])
            ->getMock();
        $mailer->setQueue($queueFake);
        $mailable = new MailableQueueableStub();
        $mailer->send($mailable);

        $queueFake->assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) use ($mailable) {
            return $job->mailable === $mailable;
        });
    }

    public function testQueuedMailableWithAttachmentFromDiskSent()
    {
        $this->getMockBuilder(Filesystem::class)
            ->getMock();
        $filesystemFactory = $this->getMockBuilder(FilesystemManager::class)
            ->setConstructorArgs([$this->app])
            ->getMock();
        $this->app->instance('filesystem', $filesystemFactory);
        $queueFake = new QueueFake($this->app);
        $mailer = $this->getMockBuilder(Mailer::class)
            ->setConstructorArgs($this->getMocks())
            ->onlyMethods(['createMessage'])
            ->getMock();
        $mailer->setQueue($queueFake);
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

    protected function getMocks()
    {
        return ['smtp', m::mock(Factory::class), m::mock(TransportInterface::class)];
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
