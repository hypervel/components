<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Filesystem;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\LocalFilesystemAdapter;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Hypervel\Support\Facades\URL;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;

#[RequiresOperatingSystem('Linux|Darwin')]
#[WithConfig('filesystems.disks.local.serve', true)]
class ServeFileTest extends TestCase
{
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function () {
            Storage::extend('served-test', function (ApplicationContract $app, array $config): LocalFilesystemAdapter {
                $adapter = new FlysystemLocalAdapter($config['root']);

                return new LocalFilesystemAdapter(new Filesystem($adapter), $adapter, $config);
            });

            Storage::put('serve-file-test.txt', 'Hello World');
            Storage::put('serve-file-test.txt?pad=x', 'Hello Question');
            Storage::put('serve-file-test%2F.txt', 'Hello Percent Escape');
            Storage::put('nested/folder/serve-file-test.txt', 'Hello Nested');
            Storage::disk('served-test')->put('serve-file-test.txt', 'Hello Custom Driver');
        });

        $this->beforeApplicationDestroyed(function () {
            Storage::delete([
                'serve-file-test.txt',
                'serve-file-test.txt?pad=x',
                'serve-file-test%2F.txt',
                'nested/folder/serve-file-test.txt',
            ]);
            Storage::disk('served-test')->delete('serve-file-test.txt');
        });

        parent::setUp();
    }

    /**
     * Set up the application environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'filesystems.disks.unserved-absent' => [
                'driver' => 'local',
                'root' => $app->storagePath('app/unserved-absent'),
                'url' => '/unserved-absent',
            ],
            'filesystems.disks.unserved-false' => [
                'driver' => 'local',
                'root' => $app->storagePath('app/unserved-false'),
                'url' => '/unserved-false',
                'serve' => false,
            ],
            'filesystems.disks.served-test' => [
                'driver' => 'served-test',
                'root' => $app->storagePath('app/served-test'),
                'url' => '/served-test',
                'serve' => true,
            ],
        ]);
    }

    public function testServeConfigurationRegistersOnlyEnabledDiskRoutes(): void
    {
        $routes = Route::getRoutes();

        $this->assertNull($routes->getByName('storage.unserved-absent'));
        $this->assertNull($routes->getByName('storage.unserved-absent.upload'));
        $this->assertNull($routes->getByName('storage.unserved-false'));
        $this->assertNull($routes->getByName('storage.unserved-false.upload'));
        $this->assertNotNull($routes->getByName('storage.local'));
        $this->assertNotNull($routes->getByName('storage.local.upload'));
        $this->assertNotNull($routes->getByName('storage.served-test'));
        $this->assertNotNull($routes->getByName('storage.served-test.upload'));
    }

    public function testItCanServeAFileFromAnOptedInCustomDriver(): void
    {
        $url = URL::to(URL::temporarySignedRoute(
            'storage.served-test',
            now()->addMinutes(1),
            ['path' => 'serve-file-test.txt'],
            absolute: false,
        ));

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('Hello Custom Driver', $response->streamedContent());
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

    public function testItCanServeAFileWithUriDelimitersInThePath(): void
    {
        $url = Storage::temporaryUrl('serve-file-test.txt?pad=x', now()->addMinutes(1));

        $response = $this->get($url);

        $this->assertSame('Hello Question', $response->streamedContent());
    }

    public function testItCanServeAFileWithAnEncodedSeparatorInItsName(): void
    {
        $url = Storage::temporaryUrl('serve-file-test%2F.txt', now()->addMinutes(1));

        $response = $this->get($url);

        $this->assertSame('Hello Percent Escape', $response->streamedContent());
    }

    public function testTemporaryUrlPreservesPathSeparatorsInNestedPaths(): void
    {
        $url = Storage::temporaryUrl('nested/folder/serve-file-test.txt', now()->addMinutes(1));

        $this->assertStringContainsString('nested/folder/serve-file-test.txt', $url);

        $response = $this->get($url);

        $this->assertSame('Hello Nested', $response->streamedContent());
    }

    public function testUriDelimitersInThePathCannotHideAnExpiredUrl(): void
    {
        $url = Storage::temporaryUrl('serve-file-test.txt?pad=x', now()->subMinutes(1));

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
