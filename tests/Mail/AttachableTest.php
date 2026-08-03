<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\Mail\Attachable;
use Hypervel\Http\Testing\File;
use Hypervel\Mail\Attachment;
use Hypervel\Mail\Mailable;
use Hypervel\Tests\TestCase;

class AttachableTest extends TestCase
{
    public function testItCanHaveMacroConstructors(): void
    {
        Attachment::macro('fromInvoice', function (string $name): Attachment {
            return Attachment::fromData(fn () => 'pdf content', $name);
        });
        $mailable = new Mailable;

        $mailable->attach(new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromInvoice('foo')
                    ->as('bar')
                    ->withMime('image/jpeg');
            }
        });

        $this->assertSame([
            'data' => 'pdf content',
            'name' => 'bar',
            'options' => [
                'mime' => 'image/jpeg',
            ],
        ], $mailable->rawAttachments[0]);
    }

    public function testItCanUtiliseExistingApisOnNonMailBasedResourcesWithPath(): void
    {
        Attachment::macro('size', function (): int {
            return 99;
        });
        $notification = new class {
            public array $pathArgs;

            public function withPathAttachment(): void
            {
                $this->pathArgs = func_get_args();
            }
        };
        $attachable = new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromPath('foo.jpg')
                    ->as('bar')
                    ->withMime('text/css');
            }
        };

        $attachable->toMailAttachment()->attachWith(
            fn ($path, $attachment) => $notification->withPathAttachment($path, $attachment->as, $attachment->mime, $attachment->size()),
            fn () => null
        );

        $this->assertSame([
            'foo.jpg',
            'bar',
            'text/css',
            99,
        ], $notification->pathArgs);
    }

    public function testItCanUtiliseExistingApisOnNonMailBasedResourcesWithArgs(): void
    {
        Attachment::macro('size', function (): int {
            return 99;
        });
        $notification = new class {
            public array $dataArgs;

            public function withDataAttachment(): void
            {
                $this->dataArgs = func_get_args();
            }
        };
        $attachable = new class implements Attachable {
            public function toMailAttachment(): Attachment
            {
                return Attachment::fromData(fn () => 'expected attachment body', 'bar')
                    ->withMime('text/css');
            }
        };

        $attachable->toMailAttachment()->attachWith(
            fn () => null,
            fn ($data, $attachment) => $notification->withDataAttachment($data(), $attachment->as, $attachment->mime, $attachment->size()),
        );

        $this->assertSame([
            'expected attachment body',
            'bar',
            'text/css',
            99,
        ], $notification->dataArgs);
    }

    public function testFromUrlMethod(): void
    {
        $mailable = new class extends Mailable {
            public function build(): void
            {
                $this->attach(new class implements Attachable {
                    public function toMailAttachment(): Attachment
                    {
                        return Attachment::fromUrl('https://example.com/file.pdf')
                            ->as('example.pdf')
                            ->withMime('application/pdf');
                    }
                });
            }
        };

        $mailable->build();

        $this->assertSame([
            'file' => 'https://example.com/file.pdf',
            'options' => [
                'as' => 'example.pdf',
                'mime' => 'application/pdf',
            ],
        ], $mailable->attachments[0]);
    }

    public function testFromUploadedFileMethod(): void
    {
        $mailable = new class extends Mailable {
            public function build(): void
            {
                $this->attach(new class implements Attachable {
                    public function toMailAttachment(): Attachment
                    {
                        return Attachment::fromUploadedFile(
                            File::createWithContent('example.pdf', 'content')
                                ->mimeType('application/pdf')
                        );
                    }
                });
            }
        };

        $mailable->build();

        $this->assertSame([
            'data' => 'content',
            'name' => 'example.pdf',
            'options' => [
                'mime' => 'application/pdf',
            ],
        ], $mailable->rawAttachments[0]);
    }
}
