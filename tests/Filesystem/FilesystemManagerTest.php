<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient as GcsClient;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\Filesystem\GoogleCloudStorageAdapter;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
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

    public function testNeverCachedScopedPurgeUsesTheOnDemandNameForWholeDriverPools(): void
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
        $disk = $manager->build($scopedConfig);

        $this->assertFalse($disk->exists('missing.txt'));
        $identity = $disk->getPoolName();
        $this->assertTrue($container->make(PoolFactory::class)->has($identity));

        $manager->purge('target');

        $this->assertFalse($container->make(PoolFactory::class)->has($identity));
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

    public function testScopedDiskConfigurationTypesAreValidatedBeforeExpansion(): void
    {
        $cases = [
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

    public function testCustomPoolableDriversConvergeAndNeverReceivePoolControlMetadata(): void
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
            ],
        ]);
        Container::setInstance($container);
        $received = [];
        $manager = new FilesystemManager($container);
        $manager->extend('custom-pooled', function (Container $app, array $config) use (&$received): FilesystemAdapter {
            $received[] = $config;
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        }, poolable: true);
        $first = $manager->disk('first');
        $second = $manager->disk('second');

        $this->assertInstanceOf(FilesystemPoolProxy::class, $first);
        $this->assertSame($first->getPoolName(), $second->getPoolName());
        $this->assertFalse($first->exists('missing.txt'));
        $this->assertFalse($second->exists('missing.txt'));
        $this->assertCount(1, $received);
        $this->assertArrayNotHasKey('pool', $received[0]);
        $this->assertSame('same', $received[0]['marker']);
    }

    public function testPoolableBuiltInDriversIncludeTheLogicalNameInConstructionIdentity(): void
    {
        $config = [
            'driver' => 'local',
            'root' => $this->tempDir . '/shared-root',
        ];
        $container = $this->getContainer([
            'disks' => [
                'first' => $config,
                'second' => $config,
            ],
        ]);
        $manager = (new FilesystemManager($container))->addPoolable('local');

        $first = $manager->disk('first');
        $second = $manager->disk('second');

        $this->assertInstanceOf(FilesystemPoolProxy::class, $first);
        $this->assertInstanceOf(FilesystemPoolProxy::class, $second);
        $this->assertNotSame($first->getPoolName(), $second->getPoolName());
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
        $container->instance(PoolFactory::class, $poolManager = new PoolManager($container));
        $this->poolManagers[] = $poolManager;

        return $container;
    }
}

class InspectableFilesystemManager extends FilesystemManager
{
    public function s3ClientConfigForTest(array $config): array
    {
        return $this->s3ClientConfig($config);
    }

    public function gcsClientConfigForTest(array $config): array
    {
        return $this->gcsClientConfig($config);
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
