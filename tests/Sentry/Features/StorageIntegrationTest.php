<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use DateTimeImmutable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Filesystem\AwsS3V3Adapter;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\Sentry\Features\Storage\DecoratedFilesystem;
use Hypervel\Sentry\Features\Storage\Integration;
use Hypervel\Sentry\Features\Storage\SentryFilesystemAdapter;
use Hypervel\Sentry\Features\Storage\SentryS3V3Adapter;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Sentry\SentryTestCase;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

class StorageIntegrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testCreatesSpansFor(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks')),
        ]);

        $transaction = $this->startTransaction();

        Storage::put('foo', 'bar');
        $fooContent = Storage::get('foo');
        Storage::assertExists('foo', 'bar');
        Storage::delete('foo');
        Storage::delete(['foo', 'bar']);
        Storage::files();
        Storage::assertMissing(['foo', 'bar']);

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertArrayHasKey(1, $spans);
        $span = $spans[1];
        $this->assertSame('file.put', $span->getOp());
        $this->assertSame('foo (3 B)', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'options' => [], 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(2, $spans);
        $span = $spans[2];
        $this->assertSame('file.get', $span->getOp());
        $this->assertSame('foo', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getData());
        $this->assertSame('bar', $fooContent);

        $this->assertArrayHasKey(3, $spans);
        $span = $spans[3];
        $this->assertSame('file.assertExists', $span->getOp());
        $this->assertSame('foo', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(4, $spans);
        $span = $spans[4];
        $this->assertSame('file.delete', $span->getOp());
        $this->assertSame('foo', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(5, $spans);
        $span = $spans[5];
        $this->assertSame('file.delete', $span->getOp());
        $this->assertSame('2 paths', $span->getDescription());
        $this->assertSame(['paths' => ['foo', 'bar'], 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(6, $spans);
        $span = $spans[6];
        $this->assertSame('file.files', $span->getOp());
        $this->assertNull($span->getDescription());
        $this->assertSame(['directory' => null, 'recursive' => false, 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(7, $spans);
        $span = $spans[7];
        $this->assertSame('file.assertMissing', $span->getOp());
        $this->assertSame('2 paths', $span->getDescription());
        $this->assertSame(['paths' => ['foo', 'bar'], 'disk' => 'local', 'driver' => 'local'], $span->getData());
    }

    public function testDoesntCreateSpansWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks'), false),
        ]);

        $transaction = $this->startTransaction();

        Storage::exists('foo');

        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
    }

    public function testAdapterSpecificOperationsAndFluentAssertionsRemainInstrumented(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks')),
        ]);

        $disk = Storage::disk('local');
        $disk->put('foo.txt', 'bar');
        $disk->makeDirectory('empty');
        $transaction = $this->startTransaction();

        $result = $disk
            ->assertExists('foo.txt')
            ->assertMissing('missing')
            ->assertDirectoryEmpty('empty');

        $this->assertSame($disk, $result);
        $this->assertTrue($result->exists('foo.txt'));
        $this->assertTrue($disk->fileExists('foo.txt'));
        $this->assertTrue($disk->directoryExists('empty'));
        $this->assertIsString($disk->checksum('foo.txt'));
        $this->assertIsString($disk->mimeType('foo.txt'));

        $operations = array_map(
            static fn ($span): ?string => $span->getOp(),
            $transaction->getSpanRecorder()->getSpans(),
        );

        $this->assertSame([
            null,
            'file.assertExists',
            'file.assertMissing',
            'file.assertDirectoryEmpty',
            'file.exists',
            'file.fileExists',
            'file.directoryExists',
            'file.checksum',
            'file.mimeType',
        ], $operations);

        $disk->delete(['foo.txt']);
        $disk->deleteDirectory('empty');
    }

    public function testTemporaryUrlCapabilitiesAndCallbacksDelegateToWrappedAdapter(): void
    {
        $disks = config('filesystems.disks');
        $disks['local']['serve'] = true;
        $disks['plain'] = [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/plain'),
        ];

        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks($disks),
        ]);

        $served = Storage::disk('local');
        $plain = Storage::disk('plain');

        $this->assertTrue($served->providesTemporaryUrls());
        $this->assertTrue($served->providesTemporaryUploadUrls());
        $this->assertFalse($plain->providesTemporaryUrls());
        $this->assertFalse($plain->providesTemporaryUploadUrls());

        $expiration = new DateTimeImmutable('+5 minutes');
        $plain->buildTemporaryUrlsUsing(
            static fn (string $path): string => "https://files.test/{$path}",
        );
        $plain->buildTemporaryUploadUrlsUsing(
            static fn (string $path): array => ['url' => "https://uploads.test/{$path}", 'headers' => ['X-Test' => 'true']],
        );

        $this->assertTrue($plain->providesTemporaryUrls());
        $this->assertTrue($plain->providesTemporaryUploadUrls());
        $this->assertSame('https://files.test/foo.txt', $plain->temporaryUrl('foo.txt', $expiration));
        $this->assertSame(
            ['url' => 'https://uploads.test/foo.txt', 'headers' => ['X-Test' => 'true']],
            $plain->temporaryUploadUrl('foo.txt', $expiration),
        );
    }

    #[DataProvider('adapterDecoratorPairs')]
    public function testEveryConcreteAdapterMethodHasExplicitDecoratorOwnership(
        string $baseClass,
        string $decoratorClass,
        array $expectedInherited,
    ): void {
        $base = new ReflectionClass($baseClass);
        $decorator = new ReflectionClass($decoratorClass);
        $inherited = [];

        foreach ($base->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($decorator->getMethod($method->getName())->getDeclaringClass()->getName() === $baseClass) {
                $inherited[] = $method->getName();
            }
        }

        sort($inherited);

        // These methods either compose instrumented methods on the outer adapter,
        // own outer response/config state, or provide generic fluent/macro behavior.
        $this->assertSame($expectedInherited, $inherited);
    }

    /**
     * Provide every concrete adapter and Sentry decorator pair.
     *
     * @return array<string, array{class-string, class-string, list<string>}>
     */
    public static function adapterDecoratorPairs(): array
    {
        return [
            'filesystem adapter' => [
                FilesystemAdapter::class,
                SentryFilesystemAdapter::class,
                [
                    'assertCount',
                    'assertEmpty',
                    'directoryMissing',
                    'download',
                    'fileMissing',
                    'getAdapter',
                    'getConfig',
                    'getDriver',
                    'image',
                    'json',
                    'macroCall',
                    'missing',
                    'response',
                    'serve',
                    'serveUsing',
                    'unless',
                    'when',
                ],
            ],
            'S3 adapter' => [
                AwsS3V3Adapter::class,
                SentryS3V3Adapter::class,
                [
                    'getClient',
                    'unless',
                    'when',
                ],
            ],
        ];
    }

    public function testTransformedServedLocalDiskKeepsItsRouteAndTelemetry(): void
    {
        $disks = config('filesystems.disks');
        $disks['local']['serve'] = true;
        $disks['local']['url'] = '/sentry-storage';

        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks($disks),
        ]);

        Storage::put('served.txt', 'served through Sentry');
        $url = Storage::temporaryUrl('served.txt', new DateTimeImmutable('+5 minutes'));
        $transaction = $this->startTransaction();

        $this->assertNotNull(Route::getRoutes()->getByName('storage.local'));
        $this->assertStringContainsString('/sentry-storage/served.txt', $url);

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('served through Sentry', $response->streamedContent());
        $this->assertContains(
            'file.mimeType',
            array_map(
                static fn ($span): ?string => $span->getOp(),
                $transaction->getSpanRecorder()->getSpans(),
            ),
        );

        Storage::delete('served.txt');
    }

    public function testCloudAndRangeOperationsRemainInstrumented(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks')),
        ]);

        $disk = Storage::disk('local');
        $disk->put('range.txt', 'abcdef');
        $transaction = $this->startTransaction();

        $this->assertStringEndsWith('/range.txt', $disk->url('range.txt'));
        $stream = $disk->readStreamRange('range.txt', 1, 3);
        $this->assertIsResource($stream);
        $this->assertSame('bcd', stream_get_contents($stream));
        fclose($stream);

        $operations = array_map(
            static fn ($span): ?string => $span->getOp(),
            $transaction->getSpanRecorder()->getSpans(),
        );

        $this->assertSame([null, 'file.url', 'file.readStreamRange'], $operations);

        $disk->delete('range.txt');
    }

    public function testNestedScopedDisksUseOneOuterDecoratorAndLogicalName(): void
    {
        $disks = [
            'parent' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/scoped-parent'),
            ],
            'tenant' => [
                'driver' => 'scoped',
                'disk' => 'parent',
                'prefix' => 'tenant-prefix',
            ],
        ];

        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks($disks),
        ]);

        $disk = Storage::disk('tenant');
        $this->assertInstanceOf(DecoratedFilesystem::class, $disk);
        $this->assertNotInstanceOf(DecoratedFilesystem::class, $disk->getFilesystem());
        $this->assertFalse($disk->invalidatePool());

        $disk->put('foo.txt', 'tenant data');
        $transaction = $this->startTransaction();
        $this->assertTrue($disk->exists('foo.txt'));
        $this->assertTrue(Storage::disk('parent')->exists('tenant-prefix/foo.txt'));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertSame('tenant', $spans[1]->getData()['disk']);
        $this->assertSame('scoped', $spans[1]->getData()['driver']);

        $disk->delete('foo.txt');
    }

    public function testForgottenTransformedDiskForwardsPoolInvalidation(): void
    {
        $disks = Integration::configureDisks([
            'pooled' => [
                'driver' => 'pooled-local',
                'root' => storage_path('framework/testing/disks/pooled'),
            ],
        ]);

        $this->resetApplicationWithConfig(['filesystems.disks' => $disks]);

        $manager = $this->app->make(FilesystemManager::class);
        $manager->extend('pooled-local', static function (Container $app, array $config): FilesystemAdapter {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        }, poolable: true);

        $disk = $manager->disk('pooled');
        $this->assertInstanceOf(DecoratedFilesystem::class, $disk);
        $pooled = $disk->getFilesystem();
        $this->assertInstanceOf(FilesystemPoolProxy::class, $pooled);
        $this->assertFalse($disk->exists('missing.txt'));

        $pools = $this->app->make(PoolFactory::class);
        $this->assertTrue($pools->has($pooled->getPoolName()));

        $manager->forgetDisk('pooled');
        $manager->purge('pooled');

        $this->assertFalse($pools->has($pooled->getPoolName()));
    }

    public function testCreatesBreadcrumbsFor(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks')),
        ]);

        Storage::put('foo', 'bar');
        $fooContent = Storage::get('foo');
        Storage::assertExists('foo', 'bar');
        Storage::delete('foo');
        Storage::delete(['foo', 'bar']);
        Storage::files();

        $breadcrumbs = $this->getCurrentSentryBreadcrumbs();

        $this->assertArrayHasKey(0, $breadcrumbs);
        $span = $breadcrumbs[0];
        $this->assertSame('file.put', $span->getCategory());
        $this->assertSame('foo (3 B)', $span->getMessage());
        $this->assertSame(['path' => 'foo', 'options' => [], 'disk' => 'local', 'driver' => 'local'], $span->getMetadata());

        $this->assertArrayHasKey(1, $breadcrumbs);
        $span = $breadcrumbs[1];
        $this->assertSame('file.get', $span->getCategory());
        $this->assertSame('foo', $span->getMessage());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getMetadata());
        $this->assertSame('bar', $fooContent);

        $this->assertArrayHasKey(2, $breadcrumbs);
        $span = $breadcrumbs[2];
        $this->assertSame('file.assertExists', $span->getCategory());
        $this->assertSame('foo', $span->getMessage());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getMetadata());

        $this->assertArrayHasKey(3, $breadcrumbs);
        $span = $breadcrumbs[3];
        $this->assertSame('file.delete', $span->getCategory());
        $this->assertSame('foo', $span->getMessage());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getMetadata());

        $this->assertArrayHasKey(4, $breadcrumbs);
        $span = $breadcrumbs[4];
        $this->assertSame('file.delete', $span->getCategory());
        $this->assertSame('2 paths', $span->getMessage());
        $this->assertSame(['paths' => ['foo', 'bar'], 'disk' => 'local', 'driver' => 'local'], $span->getMetadata());

        $this->assertArrayHasKey(5, $breadcrumbs);
        $span = $breadcrumbs[5];
        $this->assertSame('file.files', $span->getCategory());
        $this->assertNull($span->getMessage());
        $this->assertSame(['directory' => null, 'recursive' => false, 'disk' => 'local', 'driver' => 'local'], $span->getMetadata());
    }

    public function testDoesntCreateBreadcrumbsWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks'), true, false),
        ]);

        Storage::exists('foo');

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    public function testDriverWorksWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => null,
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks')),
        ]);

        $disk = Storage::disk('local');

        $this->assertNotInstanceOf(DecoratedFilesystem::class, $disk);
        $this->assertFalse($disk->exists('foo'));
    }

    public function testReturnsOriginalFilesystemWhenBothOutputsAreDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks'), false, false),
        ]);

        $disk = Storage::disk('local');

        $this->assertNotInstanceOf(DecoratedFilesystem::class, $disk);
        $this->assertFalse($disk->exists('foo'));
    }

    public function testGlobalFlagsDisableTelemetryWithoutDiskOverrides(): void
    {
        $breadcrumbs = config()->array('sentry.breadcrumbs');
        $breadcrumbs['storage'] = false;
        $diskConfig = config()->array('filesystems.disks.local');
        $diskConfig['sentry_disk_name'] = 'local';
        $diskConfig['sentry_original_driver'] = $diskConfig['driver'];
        $diskConfig['driver'] = 'sentry';
        $tracing = config()->array('sentry.tracing');
        $tracing['storage'] = false;

        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs' => $breadcrumbs,
            'sentry.tracing' => $tracing,
            'filesystems.disks.local' => $diskConfig,
        ]);

        $disk = Storage::disk('local');

        $this->assertNotInstanceOf(DecoratedFilesystem::class, $disk);
        $this->assertFalse($disk->exists('foo'));
    }

    public function testResolvingDiskDoesNotModifyConfig(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks' => Integration::configureDisks(config('filesystems.disks')),
        ]);

        $originalConfig = config('filesystems.disks.local');

        Storage::disk('local');

        $this->assertEquals($originalConfig, config('filesystems.disks.local'));
    }

    public function testCreatesSpansWithoutExplicitConfigOption(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks.local' => [
                'driver' => 'sentry',
                'sentry_disk_name' => 'local',
                'sentry_original_driver' => 'local',
                'root' => storage_path('framework/testing/disks/local'),
            ],
        ]);

        $transaction = $this->startTransaction();

        Storage::exists('foo');

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('file.exists', $spans[1]->getOp());
    }

    public function testCreatesBreadcrumbsWithoutExplicitConfigOption(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks.local' => [
                'driver' => 'sentry',
                'sentry_disk_name' => 'local',
                'sentry_original_driver' => 'local',
                'root' => storage_path('framework/testing/disks/local'),
            ],
        ]);

        Storage::exists('foo');

        $breadcrumbs = $this->getCurrentSentryBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertSame('file.exists', $breadcrumbs[0]->getCategory());
    }

    public function testNamedDiskUsesResolvedLogicalNameWithoutStoredConfig(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks.local.driver' => 'sentry',
            'filesystems.disks.local.sentry_original_driver' => 'local',
        ]);

        $this->assertFalse(Storage::disk('local')->exists('missing'));
    }

    public function testAnonymousDiskRequiresStoredLogicalName(): void
    {
        $this->expectExceptionMessage('Missing `sentry_disk_name` config key for `sentry` filesystem driver.');

        Storage::build([
            'driver' => 'sentry',
            'sentry_original_driver' => 'local',
            'root' => storage_path('framework/testing/disks/anonymous'),
        ]);
    }

    public function testThrowsIfDiskConfigurationDoesntSpecifyOriginalDriver(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks.local.driver' => 'sentry',
            'filesystems.disks.local.sentry_disk_name' => 'local',
        ]);

        $this->expectExceptionMessage('Missing `sentry_original_driver` config key for `sentry` filesystem driver.');

        Storage::disk('local');
    }

    public function testThrowsIfDiskConfigurationCreatesCircularReference(): void
    {
        $this->resetApplicationWithConfig([
            'filesystems.disks.local.driver' => 'sentry',
            'filesystems.disks.local.sentry_disk_name' => 'local',
            'filesystems.disks.local.sentry_original_driver' => 'sentry',
        ]);

        $this->expectExceptionMessage('`sentry_original_driver` for Sentry storage integration cannot be the `sentry` driver.');

        Storage::disk('local');
    }
}
