<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Http\Testing\File;
use Hypervel\Mail\Attachment;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class AttachmentTest extends TestCase
{
    use VerifiesDoubles;

    public function testFromUrlWithHttpScheme(): void
    {
        $attachment = Attachment::fromUrl('http://example.com/file.pdf');

        $this->assertInstanceOf(Attachment::class, $attachment);
    }

    public function testFromUrlWithHttpsScheme(): void
    {
        $attachment = Attachment::fromUrl('https://example.com/file.pdf');

        $this->assertInstanceOf(Attachment::class, $attachment);
    }

    public function testFromUrlWithSingleLabelHost(): void
    {
        $attachment = Attachment::fromUrl('http://l/file.pdf');

        $this->assertInstanceOf(Attachment::class, $attachment);
    }

    public function testFromUrlThrowsForFtpScheme(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Attachment URLs must use the http or https scheme.'));

        Attachment::fromUrl('ftp://example.com/file.pdf');
    }

    public function testFromUrlThrowsForFileScheme(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Attachment URLs must use the http or https scheme.'));

        Attachment::fromUrl('file:///var/www/file.pdf');
    }

    public function testFromUrlThrowsForMailtoScheme(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Attachment URLs must use the http or https scheme.'));

        Attachment::fromUrl('mailto:user@example.com');
    }

    public function testFromUrlThrowsForInvalidUrl(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Attachment URLs must use the http or https scheme.'));

        Attachment::fromUrl('not-a-url');
    }

    public function testFromUrlThrowsForEmptyString(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Attachment URLs must use the http or https scheme.'));

        Attachment::fromUrl('');
    }

    public function testAsSetFilename(): void
    {
        $attachment = Attachment::fromPath('/path/to/file.pdf')
            ->as('renamed.pdf');

        $this->assertSame('renamed.pdf', $attachment->as);
    }

    public function testWithMimeSetsMimeType(): void
    {
        $attachment = Attachment::fromPath('/path/to/file.pdf')
            ->withMime('application/pdf');

        $this->assertSame('application/pdf', $attachment->mime);
    }

    public function testFluentChaining(): void
    {
        $attachment = Attachment::fromPath('/path/to/file.jpg')
            ->as('photo.jpg')
            ->withMime('image/jpeg');

        $this->assertSame('photo.jpg', $attachment->as);
        $this->assertSame('image/jpeg', $attachment->mime);
    }

    public function testIsEquivalentWithSamePath(): void
    {
        $a = Attachment::fromPath('/path/to/file.pdf')->as('file.pdf');
        $b = Attachment::fromPath('/path/to/file.pdf')->as('file.pdf');

        $this->assertTrue($a->isEquivalent($b));
    }

    public function testIsEquivalentWithDifferentPaths(): void
    {
        $a = Attachment::fromPath('/path/to/a.pdf');
        $b = Attachment::fromPath('/path/to/b.pdf');

        $this->assertFalse($a->isEquivalent($b));
    }

    public function testIsEquivalentHonorsComparisonOptions(): void
    {
        $a = Attachment::fromPath('/path/to/file.pdf')
            ->as('renamed.pdf')
            ->withMime('application/pdf');
        $b = Attachment::fromPath('/path/to/file.pdf');

        $this->assertTrue($a->isEquivalent($b, [
            'as' => 'renamed.pdf',
            'mime' => 'application/pdf',
        ]));
    }

    public function testIsEquivalentWithStorageAttachments(): void
    {
        $storage = Double::for(FilesystemAdapter::class)->strict();
        $storage->expects('mimeType')->times(2)->with('report.txt')->returns('text/plain');
        $storage->expects('get')->times(2)->with('report.txt')->returns('file content');

        $factory = Double::for(FilesystemFactory::class)->strict();
        $factory->expects('disk')->times(2)->with('documents')->returns($storage);

        $container = new Container;
        $container->instance(FilesystemFactory::class, $factory);
        Container::setInstance($container);

        $a = Attachment::fromStorageDisk('documents', 'report.txt');
        $b = Attachment::fromStorageDisk('documents', 'report.txt');

        $this->assertTrue($a->isEquivalent($b));
    }

    public function testIsEquivalentWithUploadedFileAttachments(): void
    {
        $file = File::createWithContent('example.pdf', 'content')
            ->mimeType('application/pdf');
        $a = Attachment::fromUploadedFile($file);
        $b = Attachment::fromUploadedFile($file);

        $this->assertTrue($a->isEquivalent($b));
    }

    public function testFromDataCreatesAttachment(): void
    {
        $attachment = Attachment::fromData(fn () => 'file content', 'report.txt');

        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertSame('report.txt', $attachment->as);
    }

    public function testStorageAttachmentResolvesDiskOnceAndOmitsFailedMimeDetection(): void
    {
        $storage = Double::for(FilesystemAdapter::class)->strict();
        $storage->expects('mimeType')->with('report.txt')->returns(false);
        $storage->expects('get')->with('report.txt')->returns('file content');

        $factory = Double::for(FilesystemFactory::class)->strict();
        $factory->expects('disk')->with('documents')->returns($storage);

        $container = new Container;
        $container->instance(FilesystemFactory::class, $factory);
        Container::setInstance($container);

        [$contents, $attachment] = Attachment::fromStorageDisk('documents', 'report.txt')->attachWith(
            fn () => null,
            fn ($data, Attachment $attachment) => [$data(), $attachment]
        );

        $this->assertSame('file content', $contents);
        $this->assertSame('report.txt', $attachment->as);
        $this->assertNull($attachment->mime);
    }

    public function testExplicitStorageAttachmentMimeSkipsDetection(): void
    {
        $storage = Double::for(FilesystemAdapter::class)->strict();
        $storage->expects('mimeType')->never();
        $storage->expects('get')->with('report.txt')->returns('file content');

        $factory = Double::for(FilesystemFactory::class)->strict();
        $factory->expects('disk')->with('documents')->returns($storage);

        $container = new Container;
        $container->instance(FilesystemFactory::class, $factory);
        Container::setInstance($container);

        [, $attachment] = Attachment::fromStorageDisk('documents', 'report.txt')
            ->withMime('text/plain')
            ->attachWith(
                fn () => null,
                fn ($data, Attachment $attachment) => [$data(), $attachment]
            );

        $this->assertSame('text/plain', $attachment->mime);
    }
}
