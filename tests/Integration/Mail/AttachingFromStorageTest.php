<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Attachment;
use Hypervel\Mail\Mailable;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\TestCase;
use League\Flysystem\Local\FallbackMimeTypeDetector;

class AttachingFromStorageTest extends TestCase
{
    public function testItCanAttachFromStorage(): void
    {
        Storage::disk('local')->put('/dir/foo.png', 'expected body contents');
        $mail = new MailMessage;
        $attachment = Attachment::fromStorageDisk('local', '/dir/foo.png')
            ->as('bar')
            ->withMime('text/css');

        $attachment->attachTo($mail);

        $this->assertSame([
            'data' => 'expected body contents',
            'name' => 'bar',
            'options' => [
                'mime' => 'text/css',
            ],
        ], $mail->rawAttachments[0]);

        Storage::disk('local')->delete('/dir/foo.png');
    }

    public function testItCanAttachFromStorageAndFallbackToStorageNameAndMime(): void
    {
        Storage::disk()->put('/dir/foo.png', 'expected body contents');
        $mail = new MailMessage;
        $attachment = Attachment::fromStorageDisk('local', '/dir/foo.png');

        $attachment->attachTo($mail);

        $this->assertSame([
            'data' => 'expected body contents',
            'name' => 'foo.png',
            'options' => [
                // when using "prefer-lowest" the local filesystem driver will
                // not detect the mime type based on the extension and will
                // instead fallback to "text/plain".
                'mime' => class_exists(FallbackMimeTypeDetector::class)
                    ? 'image/png'
                    : 'text/plain',
            ],
        ], $mail->rawAttachments[0]);

        Storage::disk('local')->delete('/dir/foo.png');
    }

    public function testItCanChainAttachWithMailMessage(): void
    {
        Storage::disk('local')->put('/dir/foo.png', 'expected body contents');
        $message = new MailMessage;

        $result = $message->attach(
            Attachment::fromStorageDisk('local', '/dir/foo.png')
        );

        $this->assertSame($message, $result);
    }

    // REMOVED: testItCanAttachFromCloudStorage - Hypervel omits Laravel's legacy
    // default-cloud filesystem shortcut. Use Attachment::fromStorageDisk() with a named disk.

    public function testItCanCheckForStorageBasedAttachments(): void
    {
        Storage::disk()->put('/dir/foo.png', 'expected body contents');
        $mailable = new Mailable;
        $mailable->attach(Attachment::fromStorage('/dir/foo.png'));

        $this->assertTrue($mailable->hasAttachment(Attachment::fromStorage('/dir/foo.png')));
    }
}
