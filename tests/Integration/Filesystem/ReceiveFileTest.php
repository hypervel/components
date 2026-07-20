<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Filesystem;

use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;

#[WithConfig('filesystems.disks.local.serve', true)]
class ReceiveFileTest extends TestCase
{
    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            Storage::delete([
                'receive-file-test.txt',
                'receive-file-test.txt?pad=x',
                'nested/folder/receive-file-test.txt',
            ]);
        });

        parent::setUp();
    }

    public function testItCanReceiveAFile()
    {
        $result = Storage::temporaryUploadUrl('receive-file-test.txt', now()->addMinutes(1));

        $response = $this->call('PUT', $result['url'], [], [], [], [], 'Hello World');

        $response->assertNoContent();
        Storage::assertExists('receive-file-test.txt', 'Hello World');
    }

    public function testStorageFailureReturnsServerError(): void
    {
        $result = Storage::temporaryUploadUrl('receive-file-test.txt', now()->addMinutes(1));
        $disk = m::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->with('receive-file-test.txt', 'Hello World')->andReturnFalse();
        $manager = Storage::getFacadeRoot();

        try {
            Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
            $response = $this->call('PUT', $result['url'], [], [], [], [], 'Hello World');
        } finally {
            Storage::swap($manager);
        }

        $response->assertInternalServerError();
    }

    public function testItWill403OnWrongSignature()
    {
        $result = Storage::temporaryUploadUrl('receive-file-test.txt', now()->addMinutes(1));

        $url = $result['url'] . 'c';

        $response = $this->call('PUT', $url, [], [], [], [], 'Hello World');

        $response->assertForbidden();
        Storage::assertMissing('receive-file-test.txt');
    }

    public function testItWill403OnExpiredUrl()
    {
        $result = Storage::temporaryUploadUrl('receive-file-test.txt', now()->subMinutes(1));

        $response = $this->call('PUT', $result['url'], [], [], [], [], 'Hello World');

        $response->assertForbidden();
        Storage::assertMissing('receive-file-test.txt');
    }

    public function testDownloadUrlCannotBeUsedForUpload()
    {
        Storage::put('receive-file-test.txt', 'Original Content');

        $downloadUrl = Storage::temporaryUrl('receive-file-test.txt', now()->addMinutes(1));

        $response = $this->call('PUT', $downloadUrl, [], [], [], [], 'Malicious Content');

        $response->assertForbidden();
        $this->assertSame('Original Content', Storage::get('receive-file-test.txt'));
    }

    public function testUploadUrlCannotBeUsedForDownload()
    {
        Storage::put('receive-file-test.txt', 'Secret Content');

        $uploadUrl = Storage::temporaryUploadUrl('receive-file-test.txt', now()->addMinutes(1));

        $response = $this->get($uploadUrl['url']);

        $response->assertForbidden();
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testItCanReceiveAFileWithUriDelimitersInThePath(): void
    {
        $result = Storage::temporaryUploadUrl('receive-file-test.txt?pad=x', now()->addMinutes(1));

        $response = $this->call('PUT', $result['url'], [], [], [], [], 'Hello Question');

        $response->assertNoContent();
        Storage::assertExists('receive-file-test.txt?pad=x', 'Hello Question');
        Storage::assertMissing('receive-file-test.txt');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testTemporaryUploadUrlPreservesPathSeparatorsInNestedPaths(): void
    {
        $result = Storage::temporaryUploadUrl('nested/folder/receive-file-test.txt', now()->addMinutes(1));

        $this->assertStringContainsString('nested/folder/receive-file-test.txt', $result['url']);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testUriDelimitersInThePathCannotHideAnExpiredUploadUrl(): void
    {
        $result = Storage::temporaryUploadUrl('receive-file-test.txt?pad=x', now()->subMinutes(1));

        $response = $this->call('PUT', $result['url'], [], [], [], [], 'Hello Question');

        $response->assertForbidden();
        Storage::assertMissing('receive-file-test.txt');
        Storage::assertMissing('receive-file-test.txt?pad=x');
    }
}
