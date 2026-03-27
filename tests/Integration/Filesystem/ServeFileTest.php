<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Filesystem;

use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('filesystems.disks.local.serve', true)]
class ServeFileTest extends TestCase
{
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            Storage::put('serve-file-test.txt', 'Hello World');
        });

        $this->beforeApplicationDestroyed(function () {
            Storage::delete('serve-file-test.txt');
        });

        parent::setUp();
    }

    public function testItCanServeAnExistingFile()
    {
        $url = Storage::temporaryUrl('serve-file-test.txt', now()->addMinutes(1));

        $response = $this->get($url);

        $this->assertEquals('Hello World', $response->streamedContent());
    }

    public function testItWill404OnMissingFile()
    {
        $url = Storage::temporaryUrl('serve-missing-test.txt', now()->addMinutes(1));

        $response = $this->get($url);

        $response->assertNotFound();
    }

    public function testItWill403OnWrongSignature()
    {
        $url = Storage::temporaryUrl('serve-file-test.txt', now()->addMinutes(1));

        $url = $url . 'c';

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function testHeadRequestSendsHeadersButNoBody()
    {
        $url = Storage::temporaryUrl('serve-file-test.txt', now()->addMinutes(1));

        $response = $this->head($url);

        $response->assertOk();
        $response->assertStreamed();
        $this->assertSame('', $response->streamedContent());
    }
}
