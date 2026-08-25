<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Watcher\Fixtures\ContainerStub;
use Hypervel\Tests\Watcher\Fixtures\ScanFileDriverStub;
use Hypervel\Watcher\Driver\ScanFileDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use Mockery as m;

class ScanFileDriverTest extends TestCase
{
    protected string $fixturePath;

    protected string $fixtureRelativePath = 'watcher-scan-driver-test';

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->fixturePath = base_path($this->fixtureRelativePath);
        $this->filesystem->deleteDirectory($this->fixturePath);
        $this->filesystem->ensureDirectoryExists($this->fixturePath);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function testWatchEstablishesAnImmediateBaselineAndReportsTheNextSnapshot(): void
    {
        $option = new Option(driver: ScanFileDriver::class, scanInterval: 1);
        $channel = new Channel(1);
        $driver = new ScanFileDriverStub($option, ContainerStub::getLogger());
        $finished = new WaitGroup(1);

        Coroutine::create(function () use ($channel, $driver, $finished): void {
            try {
                $driver->watch($channel);
            } finally {
                $finished->done();
            }
        });

        try {
            $this->assertSame('.env', $channel->pop(0.1));
        } finally {
            $driver->stop();
            $this->assertTrue($finished->wait(0.1));
            $channel->close();
        }
    }

    public function testAddedModifiedAndDeletedFilesAreReportedIndependently(): void
    {
        $driver = new ScanFileDriverTestProxy(new Option, ContainerStub::getLogger());
        $channel = new Channel(3);

        try {
            $driver->processForTest($channel, [
                '/tmp/unchanged.php' => 'same',
                '/tmp/modified.php' => 'old',
                '/tmp/deleted.php' => 'deleted',
            ]);
            $driver->processForTest($channel, [
                '/tmp/unchanged.php' => 'same',
                '/tmp/modified.php' => 'new',
                '/tmp/added.php' => 'added',
            ]);

            $this->assertSame('/tmp/added.php', $channel->pop(0.1));
            $this->assertSame('/tmp/deleted.php', $channel->pop(0.1));
            $this->assertSame('/tmp/modified.php', $channel->pop(0.1));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testSnapshotOrderingDoesNotCreateFalseChanges(): void
    {
        $driver = new ScanFileDriverTestProxy(new Option, ContainerStub::getLogger());
        $channel = new Channel(1);

        try {
            $driver->processForTest($channel, [
                '/tmp/a.php' => 'a',
                '/tmp/b.php' => 'b',
            ]);
            $driver->processForTest($channel, [
                '/tmp/b.php' => 'b',
                '/tmp/a.php' => 'a',
            ]);

            $this->assertFalse($channel->pop(0.01));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testLogsOnlyWhenAProcessedSnapshotContainsChanges(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('debug')
            ->once()
            ->with(ScanFileDriver::class . ' Watching: Total:2, Change:1, Add:1, Delete:1.');
        $driver = new ScanFileDriverTestProxy(new Option, $logger);
        $channel = new Channel(3);

        try {
            $driver->processForTest($channel, ['/tmp/a.php' => 'old', '/tmp/deleted.php' => 'old']);
            $driver->processForTest($channel, ['/tmp/a.php' => 'old', '/tmp/deleted.php' => 'old']);
            $driver->processForTest($channel, ['/tmp/a.php' => 'new', '/tmp/added.php' => 'new']);
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testStreamsRecursiveHiddenFilesAndPreservesVcsExclusion(): void
    {
        $this->putFixture('.hidden.php', 'root hidden');
        $this->putFixture('.hidden/nested.php', 'nested hidden');
        $this->putFixture('visible/nested.php', 'visible');
        $this->putFixture('.git/ignored.php', 'ignored');
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath(
                $this->fixtureRelativePath,
                WatchPathType::Directory,
                $this->fixtureRelativePath . '/**/*.php',
            ),
        ]), ContainerStub::getLogger());

        $hashes = $driver->fileHashesForTest();

        $this->assertArrayHasKey($this->fixturePath . '/.hidden.php', $hashes);
        $this->assertArrayHasKey($this->fixturePath . '/.hidden/nested.php', $hashes);
        $this->assertArrayHasKey($this->fixturePath . '/visible/nested.php', $hashes);
        $this->assertArrayNotHasKey($this->fixturePath . '/.git/ignored.php', $hashes);
    }

    public function testFollowsASymlinkOperandButNotSymlinksFoundDuringDescent(): void
    {
        $this->putFixture('real/direct.php', 'direct');
        $this->putFixture('outside/nested.php', 'nested');
        symlink($this->fixturePath . '/outside', $this->fixturePath . '/real/nested-link');
        symlink($this->fixturePath . '/real', $this->fixturePath . '/root-link');
        $linkBase = $this->fixtureRelativePath . '/root-link';
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($linkBase, WatchPathType::Directory),
        ]), ContainerStub::getLogger());

        $hashes = $driver->fileHashesForTest();

        $this->assertArrayHasKey($this->fixturePath . '/root-link/direct.php', $hashes);
        $this->assertArrayNotHasKey($this->fixturePath . '/root-link/nested-link/nested.php', $hashes);
    }

    public function testPreservesParentSegmentsWhenMatchingASiblingPath(): void
    {
        $this->filesystem->makeDirectory($this->fixturePath . '/app');
        $this->putFixture('sibling/File.php', 'sibling');
        $siblingBase = $this->fixtureRelativePath . '/app/../sibling';
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($siblingBase, WatchPathType::Directory, $siblingBase . '/*.php'),
        ]), ContainerStub::getLogger());
        $path = $this->fixturePath . '/app/../sibling/File.php';

        $this->assertSame([$path => hash_file('xxh128', $path)], $driver->fileHashesForTest());
    }

    public function testShallowGlobDoesNotTraverseNestedTrees(): void
    {
        $this->putFixture('root.php', 'root');
        $this->putFixture('vendor/package.php', 'vendor');
        $this->putFixture('node_modules/package.php', 'node');
        $this->putFixture('storage/cache.php', 'storage');
        $filesystem = new CountingFilesystem;
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath(
                $this->fixtureRelativePath,
                WatchPathType::Directory,
                $this->fixtureRelativePath . '/*.php',
            ),
        ]), ContainerStub::getLogger(), $filesystem);

        $hashes = $driver->fileHashesForTest();

        $this->assertSame([$this->fixturePath . '/root.php'], array_keys($hashes));
        $this->assertSame([$this->fixturePath . '/root.php'], $filesystem->hashedPaths);
    }

    public function testIdenticalTargetsWalkOnceAndRecursiveTraversalWins(): void
    {
        $this->putFixture('root.php', 'root');
        $this->putFixture('nested/file.php', 'nested');
        $filesystem = new CountingFilesystem;
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath(
                $this->fixtureRelativePath,
                WatchPathType::Directory,
                $this->fixtureRelativePath . '/*.php',
            ),
            new WatchPath(
                $this->fixtureRelativePath,
                WatchPathType::Directory,
                $this->fixtureRelativePath . '/**/*.php',
            ),
        ]), ContainerStub::getLogger(), $filesystem);

        $hashes = $driver->fileHashesForTest();

        $this->assertCount(2, $hashes);
        $this->assertEqualsCanonicalizing(
            [$this->fixturePath . '/root.php', $this->fixturePath . '/nested/file.php'],
            $filesystem->hashedPaths,
        );
        $this->assertCount(2, $filesystem->hashedPaths);
    }

    public function testOverlappingDifferentRootsAndExplicitFilesHashEachMatchedPathOnce(): void
    {
        $this->putFixture('nested/file.php', 'nested');
        $path = $this->fixturePath . '/nested/file.php';
        $filesystem = new CountingFilesystem;
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($this->fixtureRelativePath, WatchPathType::Directory),
            new WatchPath($this->fixtureRelativePath . '/nested', WatchPathType::Directory),
            new WatchPath($this->fixtureRelativePath . '/nested/file.php', WatchPathType::File),
        ]), ContainerStub::getLogger(), $filesystem);

        $this->assertSame([$path => hash_file('xxh128', $path)], $driver->fileHashesForTest());
        $this->assertSame([$path], $filesystem->hashedPaths);
    }

    public function testMissingTargetsContributeNoEntriesAndDoNotHideLaterRoots(): void
    {
        $this->putFixture('present/file.php', 'present');
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($this->fixtureRelativePath . '/missing', WatchPathType::Directory),
            new WatchPath($this->fixtureRelativePath . '/present', WatchPathType::Directory),
            new WatchPath($this->fixtureRelativePath . '/missing.php', WatchPathType::File),
        ]), ContainerStub::getLogger());

        $this->assertSame(
            [$this->fixturePath . '/present/file.php'],
            array_keys($driver->fileHashesForTest()),
        );
    }

    public function testUnreadableSubtreeDoesNotHideReadableSiblings(): void
    {
        $this->putFixture('readable/file.php', 'readable');
        $this->putFixture('unreadable/file.php', 'unreadable');
        $unreadablePath = $this->fixturePath . '/unreadable';
        chmod($unreadablePath, 0000);
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($this->fixtureRelativePath, WatchPathType::Directory),
        ]), ContainerStub::getLogger());

        try {
            $hashes = $driver->fileHashesForTest();

            $this->assertArrayHasKey($this->fixturePath . '/readable/file.php', $hashes);

            if (! is_readable($unreadablePath)) {
                $this->assertArrayNotHasKey($unreadablePath . '/file.php', $hashes);
            }
        } finally {
            chmod($unreadablePath, 0777);
        }
    }

    public function testUnreadableWatchedRootDoesNotHideOtherRootsAndRecoversAsDeleteAdd(): void
    {
        $this->putFixture('readable/file.php', 'readable');
        $this->putFixture('unreadable/file.php', 'unreadable');
        $readableFile = $this->fixturePath . '/readable/file.php';
        $unreadableFile = $this->fixturePath . '/unreadable/file.php';
        $unreadablePath = $this->fixturePath . '/unreadable';
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($this->fixtureRelativePath . '/readable', WatchPathType::Directory),
            new WatchPath($this->fixtureRelativePath . '/unreadable', WatchPathType::Directory),
        ]), ContainerStub::getLogger());
        $channel = new Channel(2);

        try {
            $driver->processForTest($channel, $driver->fileHashesForTest());
            chmod($unreadablePath, 0000);
            clearstatcache(true, $unreadablePath);

            if (is_readable($unreadablePath)) {
                $this->markTestSkipped('The current user can still read directories with mode 0000.');
            }

            $unreadableSnapshot = $driver->fileHashesForTest();

            $this->assertArrayHasKey($readableFile, $unreadableSnapshot);
            $this->assertArrayNotHasKey($unreadableFile, $unreadableSnapshot);

            $driver->processForTest($channel, $unreadableSnapshot);
            $this->assertSame($unreadableFile, $channel->pop(0.1));

            chmod($unreadablePath, 0777);
            clearstatcache(true, $unreadablePath);
            $recoveredSnapshot = $driver->fileHashesForTest();

            $this->assertArrayHasKey($readableFile, $recoveredSnapshot);
            $this->assertArrayHasKey($unreadableFile, $recoveredSnapshot);

            $driver->processForTest($channel, $recoveredSnapshot);
            $this->assertSame($unreadableFile, $channel->pop(0.1));
            $this->assertFalse($channel->pop(0.01));
        } finally {
            chmod($unreadablePath, 0777);
            $driver->stop();
            $channel->close();
        }
    }

    public function testHashFailureOmitsTheFileFromTheSnapshot(): void
    {
        $this->putFixture('unreadable.php', 'contents');
        $path = $this->fixturePath . '/unreadable.php';
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('hash')->once()->with($path)->andReturn(false);
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($this->fixtureRelativePath . '/unreadable.php', WatchPathType::File),
        ]), ContainerStub::getLogger(), $filesystem);

        $this->assertSame([], $driver->fileHashesForTest());
    }

    public function testSameSizeRewriteWithRestoredMtimeIsDetectedByContentHash(): void
    {
        $this->putFixture('same-size.php', 'first');
        $path = $this->fixturePath . '/same-size.php';
        $modifiedAt = filemtime($path);
        $this->assertIsInt($modifiedAt);
        $driver = new ScanFileDriverTestProxy(new Option(watchPaths: [
            new WatchPath($this->fixtureRelativePath . '/same-size.php', WatchPathType::File),
        ]), ContainerStub::getLogger());
        $channel = new Channel(1);

        try {
            $driver->processForTest($channel, $driver->fileHashesForTest());
            file_put_contents($path, 'other');
            touch($path, $modifiedAt);
            clearstatcache(true, $path);
            $driver->processForTest($channel, $driver->fileHashesForTest());

            $this->assertSame($path, $channel->pop(0.1));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    public function testLargeOrderIndependentSnapshotsRemainExact(): void
    {
        $driver = new ScanFileDriverTestProxy(new Option, ContainerStub::getLogger());
        $channel = new Channel(2);
        $baseline = [];

        for ($index = 0; $index < 5000; ++$index) {
            $baseline["/tmp/{$index}.php"] = (string) $index;
        }

        $changed = array_reverse($baseline, preserve_keys: true);
        $changed['/tmp/2500.php'] = 'changed';
        $changed['/tmp/added.php'] = 'added';

        try {
            $driver->processForTest($channel, $baseline);
            $driver->processForTest($channel, $changed);

            $this->assertSame('/tmp/added.php', $channel->pop(0.1));
            $this->assertSame('/tmp/2500.php', $channel->pop(0.1));
            $this->assertFalse($channel->pop(0.01));
        } finally {
            $driver->stop();
            $channel->close();
        }
    }

    private function putFixture(string $relativePath, string $contents): void
    {
        $path = $this->fixturePath . '/' . $relativePath;
        $this->filesystem->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);
    }
}

class ScanFileDriverTestProxy extends ScanFileDriver
{
    public function processForTest(Channel $channel, array $fileHashes): void
    {
        $this->processFileHashes($channel, $fileHashes);
    }

    public function fileHashesForTest(): array
    {
        return $this->getWatchFileHashes();
    }
}

class CountingFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $hashedPaths = [];

    public function hash(string $path, string $algorithm = 'xxh128'): string|false
    {
        $this->hashedPaths[] = $path;

        return parent::hash($path, $algorithm);
    }
}
