<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\Mail\Attachable;
use Hypervel\Mail\Attachment;
use Hypervel\Mail\Mailable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AttachableTest extends TestCase
{
    public function testItCanHaveMacroConstructors()
    {
        Attachment::macro('fromInvoice', function ($name) {
            return Attachment::fromData(fn () => 'pdf content', $name);
        });
        $mailable = new Mailable();

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

    public function testItCanUtiliseExistingApisOnNonMailBasedResourcesWithPath()
    {
        Attachment::macro('size', function () {
            return 99;
        });
        $notification = new class {
            public $pathArgs;

            public function withPathAttachment()
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

    public function testItCanUtiliseExistingApisOnNonMailBasedResourcesWithArgs()
    {
        Attachment::macro('size', function () {
            return 99;
        });
        $notification = new class {
            public $pathArgs;

            public $dataArgs;

            public function withDataAttachment()
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
}
