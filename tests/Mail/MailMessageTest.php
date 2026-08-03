<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\Mail\Attachable;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Mail\Attachment;
use Hypervel\Mail\Message;
use Hypervel\Support\Str;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailMessageTest extends TestCase
{
    protected Message $message;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('MailMessageTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->tempDir);
        $filesystem->makeDirectory($this->tempDir);

        $this->message = new Message(new Email);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testFromMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->from('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getFrom()[0]);
    }

    public function testSenderMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->sender('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getSender());
    }

    public function testReturnPathMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->returnPath('foo@bar.baz'));
        $this->assertEquals(new Address('foo@bar.baz'), $message->getSymfonyMessage()->getReturnPath());
    }

    public function testToMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->to('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getTo()[0]);

        $this->assertInstanceOf(Message::class, $message = $this->message->to(['bar@bar.baz' => 'Bar']));
        $this->assertEquals(new Address('bar@bar.baz', 'Bar'), $message->getSymfonyMessage()->getTo()[0]);
    }

    public function testToMethodWithOverride(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->to('foo@bar.baz', 'Foo', true));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getTo()[0]);
    }

    public function testCcMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->cc('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getCc()[0]);
    }

    public function testBccMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->bcc('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getBcc()[0]);
    }

    public function testReplyToMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->replyTo('foo@bar.baz', 'Foo'));
        $this->assertEquals(new Address('foo@bar.baz', 'Foo'), $message->getSymfonyMessage()->getReplyTo()[0]);
    }

    public function testSubjectMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->subject('foo'));
        $this->assertSame('foo', $message->getSymfonyMessage()->getSubject());
    }

    public function testPriorityMethod(): void
    {
        $this->assertInstanceOf(Message::class, $message = $this->message->priority(1));
        $this->assertEquals(1, $message->getSymfonyMessage()->getPriority());
    }

    public function testBasicAttachment(): void
    {
        file_put_contents($path = $this->tempDir . '/foo.jpg', 'expected attachment body');

        $this->message->attach($path, ['as' => 'bar.jpg', 'mime' => 'image/png']);

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame('Content-Type: image/png; name=bar.jpg', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertSame('Content-Disposition: attachment; name=bar.jpg; filename=bar.jpg', $headers[2]);
    }

    public function testDataAttachment(): void
    {
        $this->message->attachData('expected attachment body', 'foo.jpg', ['mime' => 'image/png']);

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame('Content-Type: image/png; name=foo.jpg', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertSame('Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg', $headers[2]);
    }

    public function testItAttachesFilesViaAttachableContractFromPath(): void
    {
        file_put_contents($path = $this->tempDir . '/foo.jpg', 'expected attachment body');

        $this->message->attach(new class($path) implements Attachable {
            public function __construct(private string $path)
            {
            }

            public function toMailAttachment(): Attachment
            {
                return Attachment::fromPath($this->path)
                    ->as('bar.jpg')
                    ->withMime('image/png');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame('Content-Type: image/png; name=bar.jpg', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertSame('Content-Disposition: attachment; name=bar.jpg; filename=bar.jpg', $headers[2]);
    }

    public function testItAttachesFilesViaAttachableContractFromData(): void
    {
        $this->message->attach(new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'expected attachment body', 'foo.jpg')
                    ->withMime('image/png');
            }
        });

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame('expected attachment body', $attachment->getBody());
        $this->assertSame('Content-Type: image/png; name=foo.jpg', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertSame('Content-Disposition: attachment; name=foo.jpg; filename=foo.jpg', $headers[2]);
    }

    public function testEmbedPath(): void
    {
        file_put_contents($path = $this->tempDir . '/foo.jpg', 'bar');

        $cid = $this->message->embed($path);

        $this->assertStringStartsWith('cid:', $cid);
        $contentId = Str::after($cid, 'cid:');
        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame('bar', $attachment->getBody());
        $this->assertSame($contentId, $attachment->getContentId());
        $this->assertStringContainsString('Content-Type: image/jpeg', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertStringContainsString('Content-Disposition: inline', $headers[2]);
    }

    public function testDataEmbed(): void
    {
        $cid = $this->message->embedData('bar', 'foo.jpg', 'image/png');

        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertStringStartsWith('cid:', $cid);
        $contentId = Str::after($cid, 'cid:');
        $this->assertSame($contentId, $attachment->getContentId());
        $this->assertSame('bar', $attachment->getBody());
        $this->assertSame('Content-Type: image/png; name=foo.jpg', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertSame('Content-Disposition: inline; name=foo.jpg; filename=foo.jpg', $headers[2]);
    }

    public function testItEmbedsFilesViaAttachableContractFromPath(): void
    {
        file_put_contents($path = $this->tempDir . '/foo.jpg', 'bar');

        $cid = $this->message->embed(new class($path) implements Attachable {
            public function __construct(private string $path)
            {
            }

            public function toMailAttachment(): Attachment
            {
                return Attachment::fromPath($this->path)->as('baz')->withMime('image/png');
            }
        });

        $this->assertStringStartsWith('cid:', $cid);
        $contentId = Str::after($cid, 'cid:');
        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame($contentId, $attachment->getContentId());
        $this->assertSame('bar', $attachment->getBody());
        $this->assertSame('Content-Type: image/png; name=baz', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertSame('Content-Disposition: inline; name=baz; filename=baz', $headers[2]);
    }

    public function testItEmbedsFilesViaAttachableContractFromData(): void
    {
        $cid = $this->message->embed(new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'bar', 'foo.jpg')->withMime('image/png');
            }
        });

        $this->assertStringStartsWith('cid:', $cid);
        $contentId = Str::after($cid, 'cid:');
        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame($contentId, $attachment->getContentId());
        $this->assertSame('bar', $attachment->getBody());
        $this->assertStringContainsString('Content-Type: image/png', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertStringContainsString('Content-Disposition: inline', $headers[2]);
    }

    public function testItGeneratesARandomNameWhenAttachableHasNone(): void
    {
        file_put_contents($path = $this->tempDir . '/foo.jpg', 'bar');

        $cid = $this->message->embed(new class($path) implements Attachable {
            public function __construct(private string $path)
            {
            }

            public function toMailAttachment(): Attachment
            {
                return Attachment::fromPath($this->path);
            }
        });

        $this->assertStringStartsWith('cid:', $cid);
        $contentId = Str::after($cid, 'cid:');
        $attachment = $this->message->getSymfonyMessage()->getAttachments()[0];
        $this->assertSame($contentId, $attachment->getContentId());
        $headers = $attachment->getPreparedHeaders()->toArray();
        $this->assertSame('bar', $attachment->getBody());
        $this->assertStringContainsString('Content-Type: image/jpeg', $headers[0]);
        $this->assertSame('Content-Transfer-Encoding: base64', $headers[1]);
        $this->assertStringContainsString('Content-Disposition: inline', $headers[2]);
    }
}
