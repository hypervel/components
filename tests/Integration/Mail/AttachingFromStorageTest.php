<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Attachment;
use Hypervel\Mail\Mailable;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\TestCase;

class AttachingFromStorageTest extends TestCase
{
    public function testItCanAttachFromStorage()
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

    public function testItCanAttachFromStorageAndFallbackToStorageNameAndMime()
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
                'mime' => class_exists(\League\Flysystem\Local\FallbackMimeTypeDetector::class)
                    ? 'image/png'
                    : 'text/plain',
            ],
        ], $mail->rawAttachments[0]);

        Storage::disk('local')->delete('/dir/foo.png');
    }

    public function testItCanChainAttachWithMailMessage()
    {
        Storage::disk('local')->put('/dir/foo.png', 'expected body contents');
        $message = new MailMessage;

        $result = $message->attach(
            Attachment::fromStorageDisk('local', '/dir/foo.png')
        );

        $this->assertSame($message, $result);
    }

    public function testItCanAttachFromCloudStorage()
    {
        $this->app->make('config')->set('filesystems.cloud', 'local');

        Storage::disk('local')->put('/cloud/report.pdf', 'cloud file contents');
        $mail = new MailMessage;
        $attachment = Attachment::fromCloudStorage('/cloud/report.pdf')
            ->as('report.pdf')
            ->withMime('application/pdf');

        $attachment->attachTo($mail);

        $this->assertSame([
            'data' => 'cloud file contents',
            'name' => 'report.pdf',
            'options' => [
                'mime' => 'application/pdf',
            ],
        ], $mail->rawAttachments[0]);

        Storage::disk('local')->delete('/cloud/report.pdf');
    }

    public function testItCanCheckForStorageBasedAttachments()
    {
        Storage::disk()->put('/dir/foo.png', 'expected body contents');
        $mailable = new Mailable;
        $mailable->attach(Attachment::fromStorage('/dir/foo.png'));

        $this->assertTrue($mailable->hasAttachment(Attachment::fromStorage('/dir/foo.png')));
    }
}
