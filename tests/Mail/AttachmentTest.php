<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Mail\Attachment;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

class AttachmentTest extends TestCase
{
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment URLs must use the http or https scheme.');

        Attachment::fromUrl('ftp://example.com/file.pdf');
    }

    public function testFromUrlThrowsForFileScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment URLs must use the http or https scheme.');

        Attachment::fromUrl('file:///var/www/file.pdf');
    }

    public function testFromUrlThrowsForMailtoScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment URLs must use the http or https scheme.');

        Attachment::fromUrl('mailto:user@example.com');
    }

    public function testFromUrlThrowsForInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment URLs must use the http or https scheme.');

        Attachment::fromUrl('not-a-url');
    }

    public function testFromUrlThrowsForEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment URLs must use the http or https scheme.');

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

    public function testFromDataCreatesAttachment(): void
    {
        $attachment = Attachment::fromData(fn () => 'file content', 'report.txt');

        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertSame('report.txt', $attachment->as);
    }

    public function testStorageAttachmentResolvesDiskOnceAndOmitsFailedMimeDetection(): void
    {
        $storage = m::mock(FilesystemAdapter::class);
        $storage->shouldReceive('mimeType')->once()->with('report.txt')->andReturnFalse();
        $storage->shouldReceive('get')->once()->with('report.txt')->andReturn('file content');

        $factory = m::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('documents')->andReturn($storage);

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
        $storage = m::mock(FilesystemAdapter::class);
        $storage->shouldNotReceive('mimeType');
        $storage->shouldReceive('get')->once()->with('report.txt')->andReturn('file content');

        $factory = m::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('documents')->andReturn($storage);

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
