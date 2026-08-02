<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Testing\MimeType;
use Hypervel\Tests\TestCase;
use Symfony\Component\Mime\MimeTypesInterface;

class HttpMimeTypeTest extends TestCase
{
    public function testMimeTypeFromFileNameExistsTrue(): void
    {
        $this->assertSame('image/jpeg', MimeType::from('foo.jpg'));
    }

    public function testMimeTypeFromFileNameExistsFalse(): void
    {
        $this->assertSame('application/octet-stream', MimeType::from('foo.bar'));
    }

    public function testMimeTypeFromExtensionExistsTrue(): void
    {
        $this->assertSame('image/jpeg', MimeType::get('jpg'));
    }

    public function testMimeTypeFromExtensionExistsFalse(): void
    {
        $this->assertSame('application/octet-stream', MimeType::get('bar'));
    }

    public function testMimeTypeSymfonyInstance(): void
    {
        $this->assertInstanceOf(MimeTypesInterface::class, MimeType::getMimeTypes());
    }

    public function testSearchExtensionFromMimeType(): void
    {
        $this->assertContains(MimeType::search('video/quicktime'), ['qt', 'mov']);
        $this->assertNull(MimeType::search('foo/bar'));
    }
}
