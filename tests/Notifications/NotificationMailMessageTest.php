<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Mail\Attachable;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Mail\Attachment;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

class NotificationMailMessageTest extends TestCase
{
    protected string $filesystemRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystemRoot = ParallelTesting::tempDir('NotificationMailMessageTest');
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->filesystemRoot);
        } finally {
            parent::tearDown();
        }
    }

    public function testTemplate(): void
    {
        $message = new MailMessage;

        $this->assertSame('notifications::email', $message->markdown);

        $message->template('notifications::foo');

        $this->assertSame('notifications::foo', $message->markdown);
    }

    public function testHtmlAndPlainView(): void
    {
        $message = new MailMessage;

        $this->assertNull($message->view);
        $this->assertSame([], $message->viewData);

        $message->view(['notifications::foo', 'notifications::bar'], [
            'foo' => 'bar',
        ]);

        $this->assertSame('notifications::foo', $message->view[0]);
        $this->assertSame('notifications::bar', $message->view[1]);
        $this->assertSame(['foo' => 'bar'], $message->viewData);
    }

    public function testHtmlView(): void
    {
        $message = new MailMessage;

        $this->assertNull($message->view);
        $this->assertSame([], $message->viewData);

        $message->view('notifications::foo', [
            'foo' => 'bar',
        ]);

        $this->assertSame('notifications::foo', $message->view);
        $this->assertSame(['foo' => 'bar'], $message->viewData);
    }

    public function testPlainView(): void
    {
        $message = new MailMessage;

        $this->assertNull($message->view);
        $this->assertSame([], $message->viewData);

        $message->view([null, 'notifications::foo'], [
            'foo' => 'bar',
        ]);

        $this->assertSame('notifications::foo', $message->view[1]);
        $this->assertSame(['foo' => 'bar'], $message->viewData);
    }

    public function testCcIsSetCorrectly(): void
    {
        $message = new MailMessage;
        $message->cc('test@example.com');

        $this->assertSame([['test@example.com', null]], $message->cc);

        $message = new MailMessage;
        $message->cc('test@example.com')
            ->cc('test@example.com', 'Test');

        $this->assertSame([['test@example.com', null], ['test@example.com', 'Test']], $message->cc);

        $message = new MailMessage;
        $message->cc(['test@example.com', 'Test' => 'test@example.com']);

        $this->assertSame([['test@example.com', null], ['test@example.com', 'Test']], $message->cc);

        $message = new MailMessage;
        $message->cc('test@example.com', 'Test')
            ->cc(['test@example.com', 'test2@example.com']);

        $this->assertSame([
            ['test@example.com', 'Test'],
            ['test@example.com', null],
            ['test2@example.com', null],
        ], $message->cc);
    }

    public function testBccIsSetCorrectly(): void
    {
        $message = new MailMessage;
        $message->bcc('test@example.com');

        $this->assertSame([['test@example.com', null]], $message->bcc);

        $message = new MailMessage;
        $message->bcc('test@example.com')
            ->bcc('test@example.com', 'Test');

        $this->assertSame([['test@example.com', null], ['test@example.com', 'Test']], $message->bcc);

        $message = new MailMessage;
        $message->bcc(['test@example.com', 'Test' => 'test@example.com']);

        $this->assertSame([['test@example.com', null], ['test@example.com', 'Test']], $message->bcc);

        $message = new MailMessage;
        $message->bcc('test@example.com', 'Test')
            ->bcc(['test@example.com', 'test2@example.com']);

        $this->assertSame([
            ['test@example.com', 'Test'],
            ['test@example.com', null],
            ['test2@example.com', null],
        ], $message->bcc);
    }

    public function testReplyToIsSetCorrectly(): void
    {
        $message = new MailMessage;
        $message->replyTo('test@example.com');

        $this->assertSame([['test@example.com', null]], $message->replyTo);

        $message = new MailMessage;
        $message->replyTo('test@example.com')
            ->replyTo('test@example.com', 'Test');

        $this->assertSame([['test@example.com', null], ['test@example.com', 'Test']], $message->replyTo);

        $message = new MailMessage;
        $message->replyTo(['test@example.com', 'Test' => 'test@example.com']);

        $this->assertSame([['test@example.com', null], ['test@example.com', 'Test']], $message->replyTo);

        $message = new MailMessage;
        $message->replyTo('test@example.com', 'Test')
            ->replyTo(['test@example.com', 'test2@example.com']);

        $this->assertSame([
            ['test@example.com', 'Test'],
            ['test@example.com', null],
            ['test2@example.com', null],
        ], $message->replyTo);
    }

    public function testMetadataIsSetCorrectly(): void
    {
        $message = new MailMessage;
        $message->metadata('origin', 'test-suite');
        $message->metadata('user_id', '1');

        $this->assertArrayHasKey('origin', $message->metadata);
        $this->assertSame('test-suite', $message->metadata['origin']);
        $this->assertArrayHasKey('user_id', $message->metadata);
        $this->assertSame('1', $message->metadata['user_id']);
    }

    public function testTagIsSetCorrectly(): void
    {
        $message = new MailMessage;
        $message->tag('test');

        $this->assertContains('test', $message->tags);
    }

    public function testCallbackIsSetCorrectly(): void
    {
        $callback = function () {
        };

        $message = new MailMessage;
        $message->withSymfonyMessage($callback);

        $this->assertSame([$callback], $message->callbacks);
    }

    public function testWhenCallback(): void
    {
        $callback = function (MailMessage $mailMessage, $condition) {
            $this->assertTrue($condition);

            $mailMessage->cc('cc@example.com');
        };

        $message = new MailMessage;
        $message->when(true, $callback);
        $this->assertSame([['cc@example.com', null]], $message->cc);

        $message = new MailMessage;
        $message->when(false, $callback);
        $this->assertSame([], $message->cc);
    }

    public function testWhenCallbackWithReturn(): void
    {
        $callback = function (MailMessage $mailMessage, $condition) {
            $this->assertTrue($condition);

            return $mailMessage->cc('cc@example.com');
        };

        $message = new MailMessage;
        $message->when(true, $callback)->bcc('bcc@example.com');
        $this->assertSame([['cc@example.com', null]], $message->cc);
        $this->assertSame([['bcc@example.com', null]], $message->bcc);

        $message = new MailMessage;
        $message->when(false, $callback)->bcc('bcc@example.com');
        $this->assertSame([], $message->cc);
        $this->assertSame([['bcc@example.com', null]], $message->bcc);
    }

    public function testWhenCallbackWithDefault(): void
    {
        $callback = function (MailMessage $mailMessage, $condition) {
            $this->assertSame('truthy', $condition);

            $mailMessage->cc('truthy@example.com');
        };

        $default = function (MailMessage $mailMessage, $condition) {
            $this->assertEquals(0, $condition);

            $mailMessage->cc('zero@example.com');
        };

        $message = new MailMessage;
        $message->when('truthy', $callback, $default);
        $this->assertSame([['truthy@example.com', null]], $message->cc);

        $message = new MailMessage;
        $message->when(0, $callback, $default);
        $this->assertSame([['zero@example.com', null]], $message->cc);
    }

    public function testUnlessCallback(): void
    {
        $callback = function (MailMessage $mailMessage, $condition) {
            $this->assertFalse($condition);

            $mailMessage->cc('test@example.com');
        };

        $message = new MailMessage;
        $message->unless(false, $callback);
        $this->assertSame([['test@example.com', null]], $message->cc);

        $message = new MailMessage;
        $message->unless(true, $callback);
        $this->assertSame([], $message->cc);
    }

    public function testUnlessCallbackWithReturn(): void
    {
        $callback = function (MailMessage $mailMessage, $condition) {
            $this->assertFalse($condition);

            return $mailMessage->cc('cc@example.com');
        };

        $message = new MailMessage;
        $message->unless(false, $callback)->bcc('bcc@example.com');
        $this->assertSame([['cc@example.com', null]], $message->cc);
        $this->assertSame([['bcc@example.com', null]], $message->bcc);

        $message = new MailMessage;
        $message->unless(true, $callback)->bcc('bcc@example.com');
        $this->assertSame([], $message->cc);
        $this->assertSame([['bcc@example.com', null]], $message->bcc);
    }

    public function testUnlessCallbackWithDefault(): void
    {
        $callback = function (MailMessage $mailMessage, $condition) {
            $this->assertEquals(0, $condition);

            $mailMessage->cc('zero@example.com');
        };

        $default = function (MailMessage $mailMessage, $condition) {
            $this->assertSame('truthy', $condition);

            $mailMessage->cc('truthy@example.com');
        };

        $message = new MailMessage;
        $message->unless(0, $callback, $default);
        $this->assertSame([['zero@example.com', null]], $message->cc);

        $message = new MailMessage;
        $message->unless('truthy', $callback, $default);
        $this->assertSame([['truthy@example.com', null]], $message->cc);
    }

    public function testItAttachesFilesFromStorage(): void
    {
        $this->bootstrapFilesystem();

        $this->assertNotFalse(file_put_contents(
            $this->filesystemRoot . '/invoices/1.pdf',
            'pdf content'
        ));

        $message = new MailMessage;
        $message->attachFromStorage('invoices/1.pdf');

        $this->assertCount(1, $message->rawAttachments);
        $this->assertSame('1.pdf', $message->rawAttachments[0]['name']);
        $this->assertSame('pdf content', $message->rawAttachments[0]['data']);
    }

    public function testItAttachesFilesFromStorageDisk(): void
    {
        $this->bootstrapFilesystem();

        $this->assertNotFalse(file_put_contents(
            $this->filesystemRoot . '/s3/reports/report.txt',
            'report content'
        ));

        $message = new MailMessage;
        $message->attachFromStorageDisk('s3', 'reports/report.txt', 'monthly-report.txt', [
            'mime' => 'text/plain',
        ]);

        $this->assertCount(1, $message->rawAttachments);
        $this->assertSame('monthly-report.txt', $message->rawAttachments[0]['name']);
        $this->assertSame('report content', $message->rawAttachments[0]['data']);
        $this->assertSame('text/plain', $message->rawAttachments[0]['options']['mime']);
    }

    public function testItAttachesFilesViaAttachableContractFromPath(): void
    {
        $message = new MailMessage;

        $message->attach(new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromPath('/foo.jpg')->as('bar')->withMime('image/png');
            }
        });

        $this->assertSame([
            'file' => '/foo.jpg',
            'options' => [
                'as' => 'bar',
                'mime' => 'image/png',
            ],
        ], $message->attachments[0]);
    }

    public function testItAttachesFilesViaAttachableContractFromData(): void
    {
        $mailMessage = new MailMessage;

        $mailMessage->attach(new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'bar', 'foo.jpg')->withMime('image/png');
            }
        });

        $this->assertSame([
            'data' => 'bar',
            'name' => 'foo.jpg',
            'options' => [
                'mime' => 'image/png',
            ],
        ], $mailMessage->rawAttachments[0]);
    }

    public function testItAttachesManyFiles(): void
    {
        $mailMessage = new MailMessage;
        $attachable = new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'bar', 'foo.jpg')->withMime('image/png');
            }
        };

        $mailMessage->attachMany([
            $attachable,
            '/path/to/forge.svg',
            '/path/to/vapor.svg' => [
                'as' => 'Logo.svg',
                'mime' => 'image/svg+xml',
            ],
        ]);

        $this->assertSame([
            [
                'data' => 'bar',
                'name' => 'foo.jpg',
                'options' => [
                    'mime' => 'image/png',
                ],
            ],
        ], $mailMessage->rawAttachments);

        $this->assertSame([
            [
                'file' => '/path/to/forge.svg',
                'options' => [],
            ],
            [
                'file' => '/path/to/vapor.svg',
                'options' => [
                    'as' => 'Logo.svg',
                    'mime' => 'image/svg+xml',
                ],
            ],
        ], $mailMessage->attachments);
    }

    protected function bootstrapFilesystem(): void
    {
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->filesystemRoot);
        $filesystem->ensureDirectoryExists($this->filesystemRoot . '/invoices');
        $filesystem->ensureDirectoryExists($this->filesystemRoot . '/s3/reports');

        $container = new Container;
        Container::setInstance($container);

        $container->instance('config', new ConfigRepository([
            'filesystems' => [
                'default' => 'local',
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => $this->filesystemRoot,
                    ],
                    's3' => [
                        'driver' => 'local',
                        'root' => $this->filesystemRoot . '/s3',
                    ],
                ],
            ],
        ]));

        $container->singleton(
            'filesystem',
            fn (Container $container): FilesystemManager => new FilesystemManager($container)
        );
        $container->alias('filesystem', FilesystemFactory::class);
    }
}
