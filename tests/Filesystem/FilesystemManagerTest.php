<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Aws\S3\S3Client;
use DateTimeImmutable;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient as GcsClient;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Filesystem\AwsS3V3Adapter;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\Filesystem\GoogleCloudStorageAdapter;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter as FlysystemGcsAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use ReflectionProperty;
use RuntimeException;
use stdClass;

enum FilesystemTestStringBackedDisk: string
{
    case Local = 'local';
}

enum FilesystemTestIntBackedDisk: int
{
    case Local = 1;
}

enum FilesystemTestUnitDisk
{
    case local;
}

class FilesystemManagerTest extends TestCase
{
    private string $tempDir;

    /** @var list<PoolManager> */
    private array $poolManagers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('FilesystemManager');
        $filesystem = new Flysystem(new LocalFilesystemAdapter(dirname($this->tempDir)));
        $filesystem->deleteDirectory(basename($this->tempDir));
    }

    protected function tearDownInCoroutine(): void
    {
        foreach ($this->poolManagers as $poolManager) {
            $poolManager->flush();
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $filesystem = new Flysystem(
                new LocalFilesystemAdapter(dirname($this->tempDir))
            );
            $filesystem->deleteDirectory(basename($this->tempDir));
        }

        parent::tearDown();
    }

    public function testExceptionThrownOnUnsupportedDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Disk [local] does not have a configured driver.');

        $container = $this->getContainer([
            'disks' => [
                'local' => [],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $filesystem->disk('local');
    }

    public function testCanBuildOnDemandDisk(): void
    {
        $filesystem = new FilesystemManager($this->getContainer());

        $this->assertInstanceOf(Filesystem::class, $filesystem->build($this->tempDir . '/custom'));

        $this->assertInstanceOf(Filesystem::class, $filesystem->build([
            'driver' => 'local',
            'root' => $this->tempDir . '/configured',
            'url' => 'my-custom-url',
            'visibility' => 'public',
        ]));
    }

    public function testCanBuildReadOnlyDisks(): void
    {
        $filesystem = new FilesystemManager($this->getContainer());

        $disk = $filesystem->build([
            'driver' => 'local',
            'read-only' => true,
            'root' => $this->tempDir . '/read-only',
            'url' => 'my-custom-url',
            'visibility' => 'public',
        ]);

        file_put_contents($this->tempDir . '/read-only/path.txt', 'contents');

        // read operations work
        $this->assertEquals('contents', $disk->get('path.txt'));
        $this->assertEquals(['path.txt'], $disk->files());

        // write operations fail
        $this->assertFalse($disk->put('path.txt', 'contents'));
        $this->assertFalse($disk->delete('path.txt'));
        $this->assertFalse($disk->deleteDirectory('directory'));
        $this->assertFalse($disk->prepend('path.txt', 'data'));
        $this->assertFalse($disk->append('path.txt', 'data'));
        $handle = fopen('php://memory', 'rw');
        fwrite($handle, 'content');
        $this->assertFalse($disk->writeStream('path.txt', $handle));
        fclose($handle);
    }

    public function testCanBuildScopedDisks(): void
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => $this->tempDir . '/scoped',
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $local = $filesystem->disk('local');
            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'local',
                'prefix' => 'path-prefix',
            ]);

            $scoped->put('dirname/filename.txt', 'file content');
            $this->assertEquals('file content', $local->get('path-prefix/dirname/filename.txt'));
            $local->deleteDirectory('path-prefix');
        } finally {
            rmdir($this->tempDir . '/scoped');
        }
    }

    public function testScopedLocalDiskUsesItsNearestServedRouteForSignedUrls(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/signed-scoped',
                    'serve' => true,
                ],
                'uploads' => [
                    'driver' => 'scoped',
                    'disk' => 'local',
                    'prefix' => 'tenant',
                ],
            ],
        ]);
        $expiration = new DateTimeImmutable('+5 minutes');
        $url = m::mock();
        $url->shouldReceive('temporarySignedRoute')
            ->once()
            ->with('storage.local', $expiration, ['path' => 'tenant/file.txt'], false)
            ->andReturn('/signed/file.txt');
        $url->shouldReceive('to')
            ->once()
            ->with('/signed/file.txt')
            ->andReturn('https://example.test/signed/file.txt');
        $container->instance('url', $url);

        $filesystem = new FilesystemManager($container);

        $this->assertSame(
            'https://example.test/signed/file.txt',
            $filesystem->disk('uploads')->temporaryUrl('file.txt', $expiration),
        );
    }

    public function testNestedScopedLocalDiskUsesOriginalRoutePrefixes(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/nested-signed-scoped',
                    'prefix' => 'root',
                    'serve' => true,
                ],
                'tenant' => [
                    'driver' => 'scoped',
                    'disk' => 'local',
                    'prefix' => 'tenant',
                ],
                'documents' => [
                    'driver' => 'scoped',
                    'disk' => 'tenant',
                    'prefix' => 'documents',
                ],
            ],
        ]);
        $expiration = new DateTimeImmutable('+5 minutes');
        $url = m::mock();
        $url->shouldReceive('temporarySignedRoute')
            ->once()
            ->with('storage.local', $expiration, ['path' => 'tenant/documents/file.txt'], false)
            ->andReturn('/signed/file.txt');
        $url->shouldReceive('to')
            ->once()
            ->with('/signed/file.txt')
            ->andReturn('https://example.test/signed/file.txt');
        $container->instance('url', $url);
        $filesystem = new FilesystemManager($container);
        $disk = $filesystem->disk('documents');

        $this->assertSame('root/tenant/documents', $disk->getConfig()['prefix']);
        $this->assertSame(
            'https://example.test/signed/file.txt',
            $disk->temporaryUrl('file.txt', $expiration),
        );
    }

    public function testNestedScopedDiskComposesStoragePrefixesWithTheBaseSeparatorOnce(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/nested-scoped-separator',
                    'prefix' => 'root',
                    'directory_separator' => '\\',
                ],
                'tenant' => [
                    'driver' => 'scoped',
                    'disk' => 'local',
                    'prefix' => 'tenant',
                ],
                'documents' => [
                    'driver' => 'scoped',
                    'disk' => 'tenant',
                    'prefix' => 'documents',
                ],
            ],
        ]);

        $disk = (new FilesystemManager($container))->disk('documents');

        $this->assertSame('root\tenant\documents', $disk->getConfig()['prefix']);
    }

    public function testAnonymousScopedDiskCanUseANamedServedAncestorRoute(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/anonymous-signed-scoped',
                    'serve' => true,
                ],
            ],
        ]);
        $expiration = new DateTimeImmutable('+5 minutes');
        $url = m::mock();
        $url->shouldReceive('temporarySignedRoute')
            ->once()
            ->with('storage.local', $expiration, ['path' => 'tenant/file.txt'], false)
            ->andReturn('/signed/file.txt');
        $url->shouldReceive('to')
            ->once()
            ->with('/signed/file.txt')
            ->andReturn('https://example.test/signed/file.txt');
        $container->instance('url', $url);
        $filesystem = new FilesystemManager($container);
        $disk = $filesystem->build([
            'driver' => 'scoped',
            'disk' => 'local',
            'prefix' => 'tenant',
        ]);

        $this->assertSame(
            'https://example.test/signed/file.txt',
            $disk->temporaryUrl('file.txt', $expiration),
        );
    }

    public function testScopedDiskPreservesViewBehaviorAndPoolOverrides(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/scoped-overrides',
                    'visibility' => 'public',
                    'throw' => true,
                    'report' => false,
                ],
                'archive' => [
                    'driver' => 'scoped',
                    'disk' => 'local',
                    'prefix' => 'archive',
                    'visibility' => 'private',
                    'throw' => false,
                    'report' => true,
                    'read-only' => true,
                    'pool' => [
                        'fingerprint' => 'archive-view',
                        'max_objects' => 3,
                    ],
                ],
            ],
        ]);
        Container::setInstance($container);
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->shouldReceive('report')->once()->with(m::type(UnableToWriteFile::class));
        $container->instance(ExceptionHandler::class, $exceptionHandler);
        $filesystem = (new FilesystemManager($container))->addPoolable('local');
        $disk = $filesystem->disk('archive');

        $this->assertInstanceOf(FilesystemPoolProxy::class, $disk);
        $this->assertSame('private', $disk->getConfig()['visibility']);
        $this->assertFalse($disk->getConfig()['throw']);
        $this->assertTrue($disk->getConfig()['report']);
        $this->assertTrue($disk->getConfig()['read-only']);
        $this->assertSame(PoolFingerprint::fromExplicit('archive-view'), $disk->getDefinition()->fingerprint);
        $this->assertSame(3, $disk->getDefinition()->options->maxObjects);
        $this->assertFalse($disk->put('file.txt', 'contents'));
    }

    public function testDisksDoNotExposePoolConstructionMetadata(): void
    {
        $manager = (new FilesystemManager($this->getContainer()))->addPoolable('local');
        $direct = (new FilesystemManager($this->getContainer()))->build([
            'driver' => 'local',
            'root' => $this->tempDir . '/unpooled-local',
            'pool' => ['max_objects' => 2],
        ]);
        $wholeDriverPooled = $manager->build([
            'driver' => 'local',
            'root' => $this->tempDir . '/pooled-local',
            'pool' => ['max_objects' => 2],
        ]);
        $clientPooled = $manager->build([
            ...$this->s3Config('pooled-cloud'),
            'pool' => ['max_objects' => 2],
        ]);

        $this->assertInstanceOf(FilesystemPoolProxy::class, $wholeDriverPooled);
        $this->assertInstanceOf(ClientPooledFilesystem::class, $clientPooled);
        $this->assertArrayNotHasKey('pool', $direct->getConfig());
        $this->assertArrayNotHasKey('pool', $wholeDriverPooled->getConfig());
        $this->assertArrayNotHasKey('pool', $clientPooled->getConfig());
    }

    public function testServedOuterScopedDiskUsesItsOwnRouteWithoutAPathPrefix(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/served-outer-scoped',
                    'serve' => true,
                ],
                'uploads' => [
                    'driver' => 'scoped',
                    'disk' => 'local',
                    'prefix' => 'tenant',
                    'serve' => true,
                ],
            ],
        ]);
        $expiration = new DateTimeImmutable('+5 minutes');
        $url = m::mock();
        $url->shouldReceive('temporarySignedRoute')
            ->once()
            ->with('storage.uploads', $expiration, ['path' => 'file.txt'], false)
            ->andReturn('/signed/file.txt');
        $url->shouldReceive('to')
            ->once()
            ->with('/signed/file.txt')
            ->andReturn('https://example.test/signed/file.txt');
        $container->instance('url', $url);

        $filesystem = new FilesystemManager($container);

        $this->assertSame(
            'https://example.test/signed/file.txt',
            $filesystem->disk('uploads')->temporaryUrl('file.txt', $expiration),
        );
    }

    public function testAnonymousServedLocalDiskCannotClaimAConfiguredRoute(): void
    {
        $filesystem = new FilesystemManager($this->getContainer());
        $disk = $filesystem->build([
            'driver' => 'local',
            'root' => $this->tempDir . '/anonymous-served',
            'serve' => true,
        ]);

        $this->assertFalse($disk->providesTemporaryUrls());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This disk does not have a registered file-serving route.');

        $disk->temporaryUrl('file.txt', new DateTimeImmutable('+5 minutes'));
    }

    public function testNamedOnDemandLocalDiskUsesTheMatchingConfiguredRoute(): void
    {
        $config = [
            'driver' => 'local',
            'root' => $this->tempDir . '/named-on-demand-served',
            'serve' => true,
        ];
        $container = $this->getContainer(['disks' => ['public' => $config]]);
        $expiration = new DateTimeImmutable('+5 minutes');
        $url = m::mock();
        $url->shouldReceive('temporarySignedRoute')
            ->once()
            ->with('storage.public', $expiration, ['path' => 'file.txt'], false)
            ->andReturn('/signed/file.txt');
        $url->shouldReceive('to')
            ->once()
            ->with('/signed/file.txt')
            ->andReturn('https://example.test/signed/file.txt');
        $container->instance('url', $url);

        $disk = (new FilesystemManager($container))->build($config, 'public');

        $this->assertSame(
            'https://example.test/signed/file.txt',
            $disk->temporaryUrl('file.txt', $expiration),
        );
    }

    public function testCanBuildScopedDiskFromScopedDisk(): void
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => $this->tempDir . '/nested-scoped',
                    ],
                    'scoped-from-root' => [
                        'driver' => 'scoped',
                        'disk' => 'local',
                        'prefix' => 'scoped-from-root-prefix',
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $root = $filesystem->disk('local');
            $nestedScoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'scoped-from-root',
                'prefix' => 'nested-scoped-prefix',
            ]);

            $nestedScoped->put('dirname/filename.txt', 'file content');
            $this->assertEquals('file content', $root->get('scoped-from-root-prefix/nested-scoped-prefix/dirname/filename.txt'));
            $root->deleteDirectory('scoped-from-root-prefix');
        } finally {
            rmdir($this->tempDir . '/nested-scoped');
        }
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testCanBuildScopedDisksWithVisibility(): void
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => $this->tempDir . '/visibility-scoped',
                        'visibility' => 'public',
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'local',
                'prefix' => 'path-prefix',
                'visibility' => 'private',
            ]);

            $scoped->put('dirname/filename.txt', 'file content');

            $this->assertEquals('private', $scoped->getVisibility('dirname/filename.txt'));
        } finally {
            unlink($this->tempDir . '/visibility-scoped/path-prefix/dirname/filename.txt');
            rmdir($this->tempDir . '/visibility-scoped/path-prefix/dirname');
            rmdir($this->tempDir . '/visibility-scoped/path-prefix');
            rmdir($this->tempDir . '/visibility-scoped');
        }
    }

    public function testCanBuildScopedDisksWithThrow(): void
    {
        try {
            $container = $this->getContainer([
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => $this->tempDir . '/throw-scoped',
                        'throw' => false,
                    ],
                ],
            ]);
            $filesystem = new FilesystemManager($container);

            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => 'local',
                'prefix' => 'path-prefix',
                'throw' => true,
            ]);

            $this->expectException(UnableToReadFile::class);
            $scoped->get('dirname/filename.txt');
        } finally {
            rmdir($this->tempDir . '/throw-scoped');
        }
    }

    public function testCanBuildInlineScopedDisks(): void
    {
        try {
            $filesystem = new FilesystemManager($this->getContainer());

            $scoped = $filesystem->build([
                'driver' => 'scoped',
                'disk' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/inline-scoped',
                ],
                'prefix' => 'path-prefix',
            ]);

            $scoped->put('dirname/filename.txt', 'file content');
            $this->assertTrue(is_dir($this->tempDir . '/inline-scoped/path-prefix'));
            $this->assertEquals(file_get_contents($this->tempDir . '/inline-scoped/path-prefix/dirname/filename.txt'), 'file content');
        } finally {
            unlink($this->tempDir . '/inline-scoped/path-prefix/dirname/filename.txt');
            rmdir($this->tempDir . '/inline-scoped/path-prefix/dirname');
            rmdir($this->tempDir . '/inline-scoped/path-prefix');
            rmdir($this->tempDir . '/inline-scoped');
        }
    }

    public function testCustomDriverClosureBoundObjectIsFilesystemManager(): void
    {
        $container = $this->getContainer([
            'disks' => [
                __CLASS__ => [
                    'driver' => __CLASS__,
                ],
            ],
        ]);
        $manager = new FilesystemManager($container);
        $boundObject = null;
        $root = $this->tempDir;
        $manager->extend(__CLASS__, function () use (&$boundObject, $root): Filesystem {
            $boundObject = $this;
            $adapter = new LocalFilesystemAdapter($root);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter);
        });

        $this->assertInstanceOf(Filesystem::class, $manager->disk(__CLASS__));
        $this->assertSame($manager, $boundObject);
    }

    public function testCustomDriversMustReturnFilesystemImplementations(): void
    {
        $manager = new FilesystemManager($this->getContainer([
            'disks' => [
                'invalid' => ['driver' => 'invalid'],
            ],
        ]));
        $manager->extend('invalid', fn (): stdClass => new stdClass);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Custom filesystem driver [invalid] must return an instance of [' . Filesystem::class . '].'
        );

        $manager->disk('invalid');
    }

    public function testPoolableDriver(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = (new FilesystemManager($container))
            ->addPoolable('local');

        Container::setInstance($container);

        $this->assertInstanceOf(FilesystemPoolProxy::class, $filesystem->disk('local'));
    }

    public function testS3DisksWithTheSameClientConfigShareOneClientPoolAcrossBuckets(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'documents' => $this->s3Config('documents'),
                'archives' => $this->s3Config('archives'),
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $documents = $manager->disk('documents');
        $archives = $manager->disk('archives');

        $this->assertInstanceOf(ClientPooledFilesystem::class, $documents);
        $this->assertInstanceOf(ClientPooledFilesystem::class, $archives);
        $this->assertSame($documents->getPoolName(), $archives->getPoolName());

        $documentsClient = $documents->withClient(static fn (object $client): object => $client);
        $archivesClient = $archives->withClient(static fn (object $client): object => $client);

        $this->assertSame($documentsClient, $archivesClient);
        $this->assertCount(1, $container->make(PoolFactory::class)->pools());
    }

    public function testS3DisksWithDifferentCredentialsUseDifferentClientPools(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'first' => $this->s3Config('documents', 'first-key'),
                'second' => $this->s3Config('documents', 'second-key'),
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $first = $manager->disk('first');
        $second = $manager->disk('second');

        $this->assertNotSame($first->getPoolName(), $second->getPoolName());
        $this->assertNotSame(
            $first->withClient(static fn (object $client): object => $client),
            $second->withClient(static fn (object $client): object => $client),
        );
        $this->assertCount(2, $container->make(PoolFactory::class)->pools());
    }

    public function testRepeatedOnDemandS3BuildsConvergeWithoutNameCollisions(): void
    {
        $container = $this->getContainer();
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $config = $this->s3Config('documents');
        $first = $manager->build($config);
        $second = $manager->build($config);

        $this->assertInstanceOf(ClientPooledFilesystem::class, $first);
        $this->assertInstanceOf(ClientPooledFilesystem::class, $second);
        $this->assertSame($first->getPoolName(), $second->getPoolName());
        $this->assertSame(
            $first->withClient(static fn (object $client): object => $client),
            $second->withClient(static fn (object $client): object => $client),
        );
    }

    public function testExplicitPoolNamesAndFingerprintsDeclareConstructionEquivalence(): void
    {
        $namedFirst = $this->s3Config('first-bucket', 'first-key');
        $namedFirst['pool'] = ['name' => 'shared-account', 'fingerprint' => 'account-v1'];
        $namedSecond = $this->s3Config('second-bucket', 'second-key');
        $namedSecond['pool'] = ['name' => 'shared-account', 'fingerprint' => 'account-v1'];
        $fingerprintedFirst = $this->s3Config('third-bucket', 'third-key');
        $fingerprintedFirst['pool'] = ['fingerprint' => 'declared-equivalent'];
        $fingerprintedSecond = $this->s3Config('fourth-bucket', 'fourth-key');
        $fingerprintedSecond['pool'] = ['fingerprint' => 'declared-equivalent'];
        $container = $this->getContainer([
            'disks' => [
                'named-first' => $namedFirst,
                'named-second' => $namedSecond,
                'fingerprinted-first' => $fingerprintedFirst,
                'fingerprinted-second' => $fingerprintedSecond,
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $firstNamed = $manager->disk('named-first');
        $secondNamed = $manager->disk('named-second');
        $firstFingerprinted = $manager->disk('fingerprinted-first');
        $secondFingerprinted = $manager->disk('fingerprinted-second');

        $this->assertSame(FilesystemManager::class . ':named:shared-account', $firstNamed->getPoolName());
        $this->assertSame($firstNamed->getPoolName(), $secondNamed->getPoolName());
        $this->assertSame($firstFingerprinted->getPoolName(), $secondFingerprinted->getPoolName());
        $this->assertNotSame($firstNamed->getPoolName(), $firstFingerprinted->getPoolName());
        $this->assertSame(
            $firstNamed->withClient(static fn (object $client): object => $client),
            $secondNamed->withClient(static fn (object $client): object => $client),
        );
        $this->assertSame(
            $firstFingerprinted->withClient(static fn (object $client): object => $client),
            $secondFingerprinted->withClient(static fn (object $client): object => $client),
        );
    }

    public function testObjectClientOptionsRequireAnExplicitFingerprint(): void
    {
        $config = [
            'driver' => 'gcs',
            'bucket' => 'documents',
            'client' => ['credentialsFetcher' => new stdClass],
        ];
        $container = $this->getContainer([
            'disks' => ['documents' => $config],
        ]);
        $manager = new FilesystemManager($container);

        try {
            $manager->disk('documents');
            $this->fail('Expected the object-valued client config to require an explicit fingerprint.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('credentialsFetcher', $exception->getMessage());
            $this->assertStringContainsString('fingerprint', $exception->getMessage());
        }

        $config['pool'] = ['fingerprint' => 'credentials-provider-v1'];
        $container = $this->getContainer([
            'disks' => ['documents' => $config],
        ]);

        $this->assertInstanceOf(
            ClientPooledFilesystem::class,
            (new FilesystemManager($container))->disk('documents'),
        );
    }

    public function testConvergedDisksRejectConflictingPoolOptions(): void
    {
        $firstConfig = $this->s3Config('documents');
        $firstConfig['pool'] = ['max_objects' => 1];
        $secondConfig = $this->s3Config('archives');
        $secondConfig['pool'] = ['max_objects' => 2];
        $container = $this->getContainer([
            'disks' => [
                'first' => $firstConfig,
                'second' => $secondConfig,
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $first = $manager->disk('first');
        $second = $manager->disk('second');
        $first->withClient(static fn (object $client): object => $client);

        try {
            $second->withClient(static fn (object $client): object => $client);
            $this->fail('Expected conflicting pool options to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('different options', $exception->getMessage());
            $this->assertStringContainsString('max_objects', $exception->getMessage());
        }
    }

    public function testScopedClientPoolOptionsMustMatchTheBaseDisk(): void
    {
        $baseConfig = $this->s3Config('documents');
        $baseConfig['pool'] = ['max_objects' => 1];
        $container = $this->getContainer([
            'disks' => [
                'cloud' => $baseConfig,
                'archive' => [
                    'driver' => 'scoped',
                    'disk' => 'cloud',
                    'prefix' => 'archive',
                    'pool' => ['max_objects' => 2],
                ],
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $manager->disk('cloud')->withClient(static fn (object $client): object => $client);

        try {
            $manager->disk('archive')->withClient(static fn (object $client): object => $client);
            $this->fail('Expected conflicting scoped client pool options to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('different options', $exception->getMessage());
            $this->assertStringContainsString('max_objects', $exception->getMessage());
        }
    }

    public function testForgetDiskDropsOnlyTheWrapperAndPreservesTheSharedPool(): void
    {
        $container = $this->getContainer([
            'disks' => ['documents' => $this->s3Config('documents')],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $first = $manager->disk('documents');
        $firstClient = $first->withClient(static fn (object $client): object => $client);
        $identity = $first->getPoolName();

        $manager->forgetDisk('documents');
        $second = $manager->disk('documents');

        $this->assertNotSame($first, $second);
        $this->assertTrue($container->make(PoolFactory::class)->has($identity));
        $this->assertSame(
            $firstClient,
            $second->withClient(static fn (object $client): object => $client),
        );
    }

    public function testPurgeClosesCachedAndNeverCachedClientPools(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'documents' => $this->s3Config('documents'),
                'archives' => $this->s3Config('archives'),
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $documents = $manager->disk('documents');
        $documents->withClient(static fn (object $client): object => $client);
        $identity = $documents->getPoolName();

        $manager->purge('documents');

        $this->assertFalse($container->make(PoolFactory::class)->has($identity));

        $manager->build($this->s3Config('archives'))
            ->withClient(static fn (object $client): object => $client);
        $this->assertTrue($container->make(PoolFactory::class)->has($identity));

        $manager->purge('archives');

        $this->assertFalse($container->make(PoolFactory::class)->has($identity));
    }

    public function testPurgeRejectsAnUnsupportedConfiguredDriver(): void
    {
        $manager = new FilesystemManager($this->getContainer([
            'disks' => [
                'missing' => ['driver' => 'missing'],
            ],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [missing] is not supported.');

        $manager->purge('missing');
    }

    public function testNeverCachedScopedPurgeClosesAClientPoolCreatedThroughEveryEquivalentPath(): void
    {
        foreach (['parent disk', 'another scoped disk', 'on-demand build'] as $source) {
            $container = $this->getContainer([
                'disks' => [
                    'cloud' => $this->s3Config('documents'),
                    'target' => [
                        'driver' => 'scoped',
                        'disk' => 'cloud',
                        'prefix' => 'target',
                    ],
                    'other' => [
                        'driver' => 'scoped',
                        'disk' => 'cloud',
                        'prefix' => 'other',
                    ],
                ],
            ]);
            Container::setInstance($container);
            $manager = new FilesystemManager($container);
            $disk = match ($source) {
                'parent disk' => $manager->disk('cloud'),
                'another scoped disk' => $manager->disk('other'),
                'on-demand build' => $manager->build($this->s3Config('archives')),
            };
            $disk->withClient(static fn (object $client): object => $client);
            $identity = $disk->getPoolName();

            $this->assertTrue($container->make(PoolFactory::class)->has($identity), $source);

            $manager->purge('target');

            $this->assertFalse($container->make(PoolFactory::class)->has($identity), $source);
        }
    }

    public function testForgottenScopedPurgeUsesTheConfiguredNameForWholeDriverPools(): void
    {
        $scopedConfig = [
            'driver' => 'scoped',
            'disk' => 'local',
            'prefix' => 'tenant',
        ];
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/scoped-pool-name',
                ],
                'target' => $scopedConfig,
            ],
        ]);
        Container::setInstance($container);
        $manager = (new FilesystemManager($container))->addPoolable('local');
        $disk = $manager->disk('target');

        $this->assertFalse($disk->exists('missing.txt'));
        $identity = $disk->getPoolName();
        $this->assertTrue($container->make(PoolFactory::class)->has($identity));

        $manager->forgetDisk('target');
        $manager->purge('target');

        $this->assertFalse($container->make(PoolFactory::class)->has($identity));
    }

    public function testPurgingNamedScopedDiskLeavesAnonymousWholeDriverPoolAlone(): void
    {
        $scopedConfig = [
            'driver' => 'scoped',
            'disk' => 'local',
            'prefix' => 'tenant',
        ];
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir . '/scoped-pool-isolation',
                ],
                'target' => $scopedConfig,
            ],
        ]);
        Container::setInstance($container);
        $manager = (new FilesystemManager($container))->addPoolable('local');
        $named = $manager->disk('target');
        $anonymous = $manager->build($scopedConfig);

        $this->assertFalse($named->exists('named-missing.txt'));
        $this->assertFalse($anonymous->exists('anonymous-missing.txt'));
        $namedIdentity = $named->getPoolName();
        $anonymousIdentity = $anonymous->getPoolName();
        $this->assertNotSame($namedIdentity, $anonymousIdentity);

        $manager->forgetDisk('target');
        $manager->purge('target');

        $pools = $container->make(PoolFactory::class);
        $this->assertFalse($pools->has($namedIdentity));
        $this->assertTrue($pools->has($anonymousIdentity));
    }

    public function testNestedScopedDisksComposePrefixesAndPurgeTheSameClientPool(): void
    {
        $outerConfig = [
            'driver' => 'scoped',
            'disk' => 'inner',
            'prefix' => 'outer',
        ];
        $container = $this->getContainer([
            'disks' => [
                'cloud' => $this->s3Config('documents'),
                'inner' => [
                    'driver' => 'scoped',
                    'disk' => 'cloud',
                    'prefix' => 'inner',
                ],
                'outer' => $outerConfig,
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $disk = $manager->build($outerConfig);

        $this->assertSame('inner' . DIRECTORY_SEPARATOR . 'outer', $disk->getConfig()['prefix']);
        $disk->withClient(static fn (object $client): object => $client);
        $identity = $disk->getPoolName();
        $this->assertTrue($container->make(PoolFactory::class)->has($identity));

        $manager->purge('outer');

        $this->assertFalse($container->make(PoolFactory::class)->has($identity));
    }

    public function testCachedScopedPurgeClosesItsClientPool(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'cloud' => $this->s3Config('documents'),
                'scoped' => [
                    'driver' => 'scoped',
                    'disk' => 'cloud',
                    'prefix' => 'tenant',
                ],
            ],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $disk = $manager->disk('scoped');
        $disk->withClient(static fn (object $client): object => $client);
        $identity = $disk->getPoolName();

        $manager->purge('scoped');

        $this->assertFalse($container->make(PoolFactory::class)->has($identity));
    }

    public function testCircularNamedScopedDiskDefinitionsAreRejected(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'first' => [
                    'driver' => 'scoped',
                    'disk' => 'second',
                    'prefix' => 'first',
                ],
                'second' => [
                    'driver' => 'scoped',
                    'disk' => 'first',
                    'prefix' => 'second',
                ],
            ],
        ]);
        $manager = new FilesystemManager($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular scoped disk definition detected: second -> first -> second.');

        $manager->disk('first');
    }

    public function testScopedDiskConfigurationIsValidatedBeforeExpansion(): void
    {
        $cases = [
            'missing disk' => [
                [
                    'driver' => 'scoped',
                    'prefix' => 'tenant',
                ],
                'Scoped disk is missing "disk" configuration option.',
            ],
            'missing prefix' => [
                [
                    'driver' => 'scoped',
                    'disk' => 'local',
                ],
                'Scoped disk is missing "prefix" configuration option.',
            ],
            'disk' => [
                [
                    'driver' => 'scoped',
                    'disk' => 123,
                    'prefix' => 'tenant',
                ],
                'Scoped disk "disk" configuration option must be a disk name or configuration array.',
            ],
            'prefix' => [
                [
                    'driver' => 'scoped',
                    'disk' => [
                        'driver' => 'local',
                        'root' => $this->tempDir,
                    ],
                    'prefix' => 123,
                ],
                'Scoped disk "prefix" configuration option must be a string.',
            ],
            'missing named parent' => [
                [
                    'driver' => 'scoped',
                    'disk' => 'missing-parent',
                    'prefix' => 'tenant',
                ],
                'Disk [missing-parent] does not have a configured driver.',
            ],
        ];

        foreach ($cases as $name => [$config, $message]) {
            try {
                (new FilesystemManager($this->getContainer()))->build($config);
                $this->fail("Expected the scoped [{$name}] type to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testScopedInlineBaseWithoutADriverNamesTheRequestingDisk(): void
    {
        $config = [
            'driver' => 'scoped',
            'disk' => ['root' => $this->tempDir],
            'prefix' => 'tenant',
        ];
        $cases = [
            'Disk [ondemand] does not have a configured driver.' => fn () => (new FilesystemManager($this->getContainer()))->build($config),
            'Disk [named-child] does not have a configured driver.' => fn () => (new FilesystemManager($this->getContainer([
                'disks' => ['named-child' => $config],
            ])))->disk('named-child'),
        ];

        foreach ($cases as $expectedMessage => $operation) {
            $failure = null;

            try {
                $operation();
            } catch (InvalidArgumentException $exception) {
                $failure = $exception;
            }

            $this->assertInstanceOf(InvalidArgumentException::class, $failure);
            $this->assertSame($expectedMessage, $failure->getMessage());
        }
    }

    public function testCustomCreatorsReceiveTheExactLogicalName(): void
    {
        $config = [
            'driver' => 'custom',
            'root' => $this->tempDir . '/custom-names',
        ];
        $container = $this->getContainer([
            'disks' => ['ondemand' => $config],
        ]);
        $received = [];
        $manager = new FilesystemManager($container);
        $manager->extend('custom', function (Container $app, array $config, ?string $name) use (&$received): FilesystemAdapter {
            $received[] = $name;
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        });

        $manager->build($config);
        $manager->build($config, 'uploads');
        $manager->disk('ondemand');

        $this->assertSame([null, 'uploads', 'ondemand'], $received);
    }

    public function testProtectedCustomCreatorRemainsCallableWithOneArgument(): void
    {
        $receivedName = 'unset';
        $manager = new InspectableFilesystemManager($this->getContainer());
        $manager->extend('custom', function (Container $app, array $config, ?string $name) use (&$receivedName): FilesystemAdapter {
            $receivedName = $name;
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        });

        $manager->callCustomCreatorForTest([
            'driver' => 'custom',
            'root' => $this->tempDir . '/custom-one-argument',
        ]);

        $this->assertNull($receivedName);
    }

    public function testCustomPoolableDriversIncludeLogicalNamesAndExcludePoolMetadata(): void
    {
        $root = $this->tempDir . '/custom-pooled';
        $config = [
            'driver' => 'custom-pooled',
            'root' => $root,
            'marker' => 'same',
            'pool' => ['max_objects' => 2],
        ];
        $container = $this->getContainer([
            'disks' => [
                'first' => $config,
                'second' => $config,
                'ondemand' => $config,
            ],
        ]);
        Container::setInstance($container);
        $received = [];
        $manager = new FilesystemManager($container);
        $manager->extend('custom-pooled', function (Container $app, array $config, ?string $name) use (&$received): FilesystemAdapter {
            $received[] = [$config, $name];
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        }, poolable: true);
        $first = $manager->disk('first');
        $second = $manager->disk('second');
        $anonymous = $manager->build($config);
        $configuredOndemand = $manager->disk('ondemand');

        $this->assertInstanceOf(FilesystemPoolProxy::class, $first);
        $this->assertNotSame($first->getPoolName(), $second->getPoolName());
        $this->assertNotSame($anonymous->getPoolName(), $configuredOndemand->getPoolName());
        $this->assertFalse($first->exists('missing.txt'));
        $this->assertFalse($second->exists('missing.txt'));
        $this->assertFalse($anonymous->exists('missing.txt'));
        $this->assertFalse($configuredOndemand->exists('missing.txt'));
        $this->assertSame(['first', 'second', null, 'ondemand'], array_column($received, 1));

        foreach ($received as [$receivedConfig]) {
            $this->assertArrayNotHasKey('pool', $receivedConfig);
            $this->assertSame('same', $receivedConfig['marker']);
        }
    }

    public function testPoolableBuiltInDriversIncludeTheLogicalNameInConstructionIdentity(): void
    {
        $config = [
            'driver' => 'local',
            'root' => $this->tempDir . '/shared-root',
            'serve' => true,
        ];
        $container = $this->getContainer([
            'disks' => [
                'first' => $config,
                'second' => $config,
                'ondemand' => $config,
            ],
        ]);
        $manager = (new FilesystemManager($container))->addPoolable('local');

        $first = $manager->disk('first');
        $second = $manager->disk('second');
        $configuredOndemand = $manager->disk('ondemand');
        $anonymous = $manager->build($config);

        $this->assertInstanceOf(FilesystemPoolProxy::class, $first);
        $this->assertInstanceOf(FilesystemPoolProxy::class, $second);
        $this->assertNotSame($first->getPoolName(), $second->getPoolName());
        $this->assertNotSame($configuredOndemand->getPoolName(), $anonymous->getPoolName());
    }

    public function testWholeDriverPoolsIncludeRouteOwnershipNotImpliedByEffectiveConfiguration(): void
    {
        $base = [
            'driver' => 'local',
            'root' => $this->tempDir . '/scoped-serving-pools',
            'serve' => true,
            'url' => '/served-files',
        ];
        $container = $this->getContainer([
            'disks' => [
                'served' => $base,
            ],
        ]);
        $manager = (new FilesystemManager($container))->addPoolable('local');
        $inlineParent = $manager->build([
            'driver' => 'scoped',
            'disk' => $base,
            'prefix' => 'tenant',
        ], 'tenant-files');
        $namedParent = $manager->build([
            'driver' => 'scoped',
            'disk' => 'served',
            'prefix' => 'tenant',
        ], 'tenant-files');

        $this->assertInstanceOf(FilesystemPoolProxy::class, $inlineParent);
        $this->assertInstanceOf(FilesystemPoolProxy::class, $namedParent);
        $this->assertSame($inlineParent->getConfig(), $namedParent->getConfig());
        $this->assertNotSame($inlineParent->getPoolName(), $namedParent->getPoolName());
    }

    public function testWholeDriverPoolsDistinguishAnonymousServingIntent(): void
    {
        $config = [
            'driver' => 'local',
            'root' => $this->tempDir . '/anonymous-serving-pools',
        ];
        $manager = (new FilesystemManager($this->getContainer()))->addPoolable('local');
        $unserved = $manager->build($config);
        $served = $manager->build([...$config, 'serve' => true]);

        $this->assertInstanceOf(FilesystemPoolProxy::class, $unserved);
        $this->assertInstanceOf(FilesystemPoolProxy::class, $served);
        $this->assertNotSame($unserved->getPoolName(), $served->getPoolName());
    }

    public function testCustomS3AndGcsCreatorsUseWholeDriverPoolIdentity(): void
    {
        $root = $this->tempDir . '/custom-cloud';
        $container = $this->getContainer([
            'disks' => [
                's3-first' => [
                    'driver' => 's3',
                    'root' => $root,
                    'bucket' => 'first',
                    'region' => 'us-east-1',
                ],
                's3-second' => [
                    'driver' => 's3',
                    'root' => $root,
                    'bucket' => 'second',
                    'region' => 'us-east-1',
                ],
                'gcs-first' => [
                    'driver' => 'gcs',
                    'root' => 'first',
                    'bucket' => 'shared',
                    'project_id' => 'project',
                ],
                'gcs-second' => [
                    'driver' => 'gcs',
                    'root' => 'second',
                    'bucket' => 'shared',
                    'project_id' => 'project',
                ],
            ],
        ]);
        $manager = new FilesystemManager($container);
        $creator = static function (Container $app, array $config): FilesystemAdapter {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        };
        $manager->extend('s3', $creator, poolable: true);
        $manager->extend('gcs', $creator, poolable: true);

        $this->assertNotSame(
            $manager->disk('s3-first')->getPoolName(),
            $manager->disk('s3-second')->getPoolName(),
        );
        $this->assertNotSame(
            $manager->disk('gcs-first')->getPoolName(),
            $manager->disk('gcs-second')->getPoolName(),
        );
    }

    public function testExplicitFingerprintOverridesWholeDriverConstructionIdentity(): void
    {
        $first = [
            'driver' => 'custom',
            'root' => $this->tempDir . '/explicit-first',
            'pool' => ['fingerprint' => 'shared-construction'],
        ];
        $second = [
            'driver' => 'custom',
            'root' => $this->tempDir . '/explicit-second',
            'pool' => ['fingerprint' => 'shared-construction'],
        ];
        $container = $this->getContainer([
            'disks' => ['first' => $first, 'second' => $second],
        ]);
        $manager = new FilesystemManager($container);
        $manager->extend('custom', static function (Container $app, array $config): FilesystemAdapter {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        }, poolable: true);

        $this->assertSame(
            $manager->disk('first')->getPoolName(),
            $manager->disk('second')->getPoolName(),
        );
    }

    public function testScopedS3BuildsShareTheParentClientPoolWithoutCollisions(): void
    {
        $container = $this->getContainer([
            'disks' => ['cloud' => $this->s3Config('documents')],
        ]);
        Container::setInstance($container);
        $manager = new FilesystemManager($container);
        $first = $manager->build([
            'driver' => 'scoped',
            'disk' => 'cloud',
            'prefix' => 'first',
        ]);
        $second = $manager->build([
            'driver' => 'scoped',
            'disk' => 'cloud',
            'prefix' => 'second',
        ]);

        $this->assertInstanceOf(ClientPooledFilesystem::class, $first);
        $this->assertInstanceOf(ClientPooledFilesystem::class, $second);
        $this->assertSame($first->getPoolName(), $second->getPoolName());
        $this->assertSame(
            $first->withClient(static fn (object $client): object => $client),
            $second->withClient(static fn (object $client): object => $client),
        );
    }

    public function testS3ClientConfigSelectsOnlySdkArgumentsAndExplicitBlockWins(): void
    {
        $filesystem = new InspectableFilesystemManager($this->getContainer());

        $config = $filesystem->s3ClientConfigForTest([
            'bucket' => 'documents',
            'root' => 'tenant',
            'region' => 'us-east-1',
            'key' => 'key',
            'secret' => 'secret',
            'token' => 'token',
            'stream_reads' => true,
            'client' => [
                'region' => 'us-west-2',
                'endpoint' => 'https://s3.example.test',
            ],
        ]);

        $this->assertSame('latest', $config['version']);
        $this->assertSame('us-west-2', $config['region']);
        $this->assertSame('https://s3.example.test', $config['endpoint']);
        $this->assertSame(['key' => 'key', 'secret' => 'secret', 'token' => 'token'], $config['credentials']);
        $this->assertArrayNotHasKey('bucket', $config);
        $this->assertArrayNotHasKey('root', $config);
        $this->assertArrayNotHasKey('stream_reads', $config);
        $this->assertArrayNotHasKey('client', $config);
        $this->assertArrayNotHasKey('key', $config);
        $this->assertArrayNotHasKey('secret', $config);
    }

    public function testS3DiskDefaultsStreamingReadsAndAllowsOptOut(): void
    {
        $filesystem = new InspectableFilesystemManager($this->getContainer());
        $client = new S3Client([
            'credentials' => false,
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);

        $default = $filesystem->buildS3DiskForTest($client, ['bucket' => 'documents']);
        $disabled = $filesystem->buildS3DiskForTest($client, [
            'bucket' => 'documents',
            'stream_reads' => false,
        ]);

        $this->assertInstanceOf(FlysystemS3Adapter::class, $default->getAdapter());
        $this->assertTrue((new ReflectionProperty(FlysystemS3Adapter::class, 'streamReads'))->getValue($default->getAdapter()));
        $this->assertFalse((new ReflectionProperty(FlysystemS3Adapter::class, 'streamReads'))->getValue($disabled->getAdapter()));
    }

    public function testGcsClientConfigSupportsFlatKeysAndTheFullExplicitSdkSurface(): void
    {
        $filesystem = new InspectableFilesystemManager($this->getContainer());

        $config = $filesystem->gcsClientConfigForTest([
            'bucket' => 'documents',
            'root' => 'tenant',
            'project_id' => 'flat-project',
            'key_file' => ['client_email' => 'test@example.test'],
            'api_endpoint' => 'https://flat.example.test',
            'client' => [
                'projectId' => 'explicit-project',
                'requestTimeout' => 10,
                'retries' => 2,
                'scopes' => ['scope-a'],
                'quotaProject' => 'billing-project',
            ],
        ]);

        $this->assertSame('explicit-project', $config['projectId']);
        $this->assertSame(['client_email' => 'test@example.test'], $config['keyFile']);
        $this->assertSame('https://flat.example.test', $config['apiEndpoint']);
        $this->assertSame(10, $config['requestTimeout']);
        $this->assertSame(2, $config['retries']);
        $this->assertSame(['scope-a'], $config['scopes']);
        $this->assertSame('billing-project', $config['quotaProject']);
        $this->assertArrayNotHasKey('bucket', $config);
        $this->assertArrayNotHasKey('root', $config);
        $this->assertArrayNotHasKey('client', $config);
    }

    public function testGcsDiskUsesTheSharedFlysystemStackConfiguration(): void
    {
        $bucket = m::mock(Bucket::class);
        $client = m::mock(GcsClient::class);
        $client->shouldReceive('bucket')->once()->with('documents')->andReturn($bucket);
        $filesystem = new InspectableFilesystemManager($this->getContainer());
        $disk = $filesystem->buildGcsDiskForTest($client, [
            'bucket' => 'documents',
            'root' => 'account-root',
            'prefix' => 'tenant',
            'read-only' => true,
            'visibility' => 'public',
            'directory_visibility' => 'private',
            'url' => 'https://storage.example.test',
            'throw' => true,
        ]);

        $driver = $disk->getDriver();
        $driverAdapter = (new ReflectionProperty(Flysystem::class, 'adapter'))->getValue($driver);

        $this->assertInstanceOf(PathPrefixedAdapter::class, $driverAdapter);
        $this->assertSame(
            'tenant/file.txt',
            (new ReflectionProperty(PathPrefixedAdapter::class, 'prefix'))
                ->getValue($driverAdapter)
                ->prefixPath('file.txt'),
        );
        $this->assertInstanceOf(
            ReadOnlyFilesystemAdapter::class,
            (new ReflectionProperty(PathPrefixedAdapter::class, 'adapter'))->getValue($driverAdapter),
        );

        $driverConfig = (new ReflectionProperty(Flysystem::class, 'config'))->getValue($driver)->toArray();
        $this->assertSame('public', $driverConfig['visibility']);
        $this->assertSame('private', $driverConfig['directory_visibility']);
        $this->assertSame('https://storage.example.test', $driverConfig['url']);
        $this->assertArrayNotHasKey('bucket', $driverConfig);
        $this->assertArrayNotHasKey('root', $driverConfig);
        $this->assertArrayNotHasKey('prefix', $driverConfig);
        $this->assertArrayNotHasKey('read-only', $driverConfig);
        $this->assertArrayNotHasKey('throw', $driverConfig);
    }

    public function testGcsDiskDefaultsStreamingReadsAndAllowsOptOut(): void
    {
        $bucket = m::mock(Bucket::class);
        $client = m::mock(GcsClient::class);
        $client->shouldReceive('bucket')->twice()->with('documents')->andReturn($bucket);
        $filesystem = new InspectableFilesystemManager($this->getContainer());

        $default = $filesystem->buildGcsDiskForTest($client, ['bucket' => 'documents']);
        $disabled = $filesystem->buildGcsDiskForTest($client, [
            'bucket' => 'documents',
            'stream_reads' => false,
        ]);

        $this->assertInstanceOf(FlysystemGcsAdapter::class, $default->getAdapter());
        $this->assertTrue((new ReflectionProperty(FlysystemGcsAdapter::class, 'streamReads'))->getValue($default->getAdapter()));
        $this->assertFalse((new ReflectionProperty(FlysystemGcsAdapter::class, 'streamReads'))->getValue($disabled->getAdapter()));
    }

    public function testUnknownExplicitClientOptionsAreRejected(): void
    {
        $filesystem = new InspectableFilesystemManager($this->getContainer());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown client option(s) [typo] in the disk "client" configuration.');

        $filesystem->gcsClientConfigForTest(['client' => ['typo' => true]]);
    }

    public function testClientOptionBlockMustBeAnArray(): void
    {
        $filesystem = new InspectableFilesystemManager($this->getContainer());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The disk "client" configuration option must be an array.');

        $filesystem->s3ClientConfigForTest(['client' => null]);
    }

    public function testS3ArgumentCacheCanBeFlushedBetweenTests(): void
    {
        $filesystem = new InspectableFilesystemManager($this->getContainer());
        $this->assertContains('version', $filesystem->s3ArgumentNamesForTest());

        FilesystemManager::flushState();

        $property = new ReflectionProperty(FilesystemManager::class, 's3ArgumentNames');
        $this->assertNull($property->getValue());
    }

    public function testDiskAcceptsStringBackedEnum(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $disk = $filesystem->disk(FilesystemTestStringBackedDisk::Local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    public function testDiskAcceptsUnitEnum(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $disk = $filesystem->disk(FilesystemTestUnitDisk::local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    public function testDiskWithIntBackedEnumResolvesAsString(): void
    {
        $container = $this->getContainer([
            'disks' => [
                '1' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        // Int-backed enum value is cast to string for disk resolution
        $disk = $filesystem->disk(FilesystemTestIntBackedDisk::Local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    public function testDriveAcceptsStringBackedEnum(): void
    {
        $container = $this->getContainer([
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => $this->tempDir,
                ],
            ],
        ]);
        $filesystem = new FilesystemManager($container);

        $disk = $filesystem->drive(FilesystemTestStringBackedDisk::Local);

        $this->assertInstanceOf(Filesystem::class, $disk);
    }

    private function s3Config(string $bucket, string $key = 'test-key'): array
    {
        return [
            'driver' => 's3',
            'bucket' => $bucket,
            'region' => 'us-east-1',
            'key' => $key,
            'secret' => 'test-secret',
        ];
    }

    protected function getContainer(array $config = []): Container
    {
        $config = new Repository(['filesystems' => $config]);

        $container = new Container;
        $container->instance('config', $config);
        $container->instance(ContainerContract::class, $container);
        $container->instance(PoolFactory::class, $poolManager = new PoolManager);
        $this->poolManagers[] = $poolManager;

        return $container;
    }
}

class InspectableFilesystemManager extends FilesystemManager
{
    public function callCustomCreatorForTest(array $config): Filesystem
    {
        return $this->callCustomCreator($config);
    }

    public function s3ClientConfigForTest(array $config): array
    {
        return $this->s3ClientConfig($config);
    }

    public function gcsClientConfigForTest(array $config): array
    {
        return $this->gcsClientConfig($config);
    }

    public function buildS3DiskForTest(S3Client $client, array $config): AwsS3V3Adapter
    {
        return $this->buildS3Disk($client, $config);
    }

    public function buildGcsDiskForTest(GcsClient $client, array $config): GoogleCloudStorageAdapter
    {
        return $this->buildGcsDisk($client, $config);
    }

    public function s3ArgumentNamesForTest(): array
    {
        return static::s3ArgumentNames();
    }
}
