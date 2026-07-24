<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Migrations\MigrationCreator;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Date;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class DatabaseMigrationCreatorTest extends TestCase
{
    public function testBasicCreateMethodStoresMigrationFile()
    {
        $creator = $this->getCreator();

        $creator->expects($this->once())->method('getDatePrefix')->willReturn('foo');
        $creator->getFilesystem()->shouldReceive('exists')->once()->with('stubs/migration.stub')->andReturn(false);
        $creator->getFilesystem()->shouldReceive('get')->once()->with($creator->stubPath() . '/migration.stub')->andReturn('return new class');
        $creator->getFilesystem()->shouldReceive('ensureDirectoryExists')->once()->with('foo');
        $creator->getFilesystem()->shouldReceive('replace')->once()->with('foo/foo_create_bar.php', 'return new class');
        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturn(['foo/foo_create_bar.php']);
        $creator->getFilesystem()->shouldReceive('requireOnce')->once()->with('foo/foo_create_bar.php');

        $creator->create('create_bar', 'foo');
    }

    public function testBasicCreateMethodCallsPostCreateHooks()
    {
        $table = 'baz';

        $creator = $this->getCreator();
        unset($_SERVER['__migration.creator.table'], $_SERVER['__migration.creator.path']);
        $creator->afterCreate(function ($table, $path) {
            $_SERVER['__migration.creator.table'] = $table;
            $_SERVER['__migration.creator.path'] = $path;
        });

        $creator->expects($this->once())->method('getDatePrefix')->willReturn('foo');
        $creator->getFilesystem()->shouldReceive('exists')->once()->with('stubs/migration.update.stub')->andReturn(false);
        $creator->getFilesystem()->shouldReceive('get')->once()->with($creator->stubPath() . '/migration.update.stub')->andReturn('return new class DummyTable');
        $creator->getFilesystem()->shouldReceive('ensureDirectoryExists')->once()->with('foo');
        $creator->getFilesystem()->shouldReceive('replace')->once()->with('foo/foo_create_bar.php', 'return new class baz');
        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturn(['foo/foo_create_bar.php']);
        $creator->getFilesystem()->shouldReceive('requireOnce')->once()->with('foo/foo_create_bar.php');

        $creator->create('create_bar', 'foo', $table);

        $this->assertEquals($_SERVER['__migration.creator.table'], $table);
        $this->assertEquals($_SERVER['__migration.creator.path'], 'foo/foo_create_bar.php');

        unset($_SERVER['__migration.creator.table'], $_SERVER['__migration.creator.path']);
    }

    public function testTableUpdateMigrationStoresMigrationFile()
    {
        $creator = $this->getCreator();
        $creator->expects($this->once())->method('getDatePrefix')->willReturn('foo');
        $creator->getFilesystem()->shouldReceive('exists')->once()->with('stubs/migration.update.stub')->andReturn(false);
        $creator->getFilesystem()->shouldReceive('get')->once()->with($creator->stubPath() . '/migration.update.stub')->andReturn('return new class DummyTable');
        $creator->getFilesystem()->shouldReceive('ensureDirectoryExists')->once()->with('foo');
        $creator->getFilesystem()->shouldReceive('replace')->once()->with('foo/foo_create_bar.php', 'return new class baz');
        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturn(['foo/foo_create_bar.php']);
        $creator->getFilesystem()->shouldReceive('requireOnce')->once()->with('foo/foo_create_bar.php');

        $creator->create('create_bar', 'foo', 'baz');
    }

    public function testTableCreationMigrationStoresMigrationFile()
    {
        $creator = $this->getCreator();
        $creator->expects($this->once())->method('getDatePrefix')->willReturn('foo');
        $creator->getFilesystem()->shouldReceive('exists')->once()->with('stubs/migration.create.stub')->andReturn(false);
        $creator->getFilesystem()->shouldReceive('get')->once()->with($creator->stubPath() . '/migration.create.stub')->andReturn('return new class DummyTable');
        $creator->getFilesystem()->shouldReceive('ensureDirectoryExists')->once()->with('foo');
        $creator->getFilesystem()->shouldReceive('replace')->once()->with('foo/foo_create_bar.php', 'return new class baz');
        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturn(['foo/foo_create_bar.php']);
        $creator->getFilesystem()->shouldReceive('requireOnce')->once()->with('foo/foo_create_bar.php');

        $creator->create('create_bar', 'foo', 'baz', true);
    }

    public function testTableUpdateMigrationWontCreateDuplicateClass()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A MigrationCreatorFakeMigration class already exists.');

        $creator = $this->getCreator([]);

        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturn(['foo/foo_create_bar.php']);
        $creator->getFilesystem()->shouldReceive('requireOnce')->once()->with('foo/foo_create_bar.php');

        $creator->create('migration_creator_fake_migration', 'foo');
    }

    public function testCustomStubIsPublishedAsTheFinalMigrationBeforeHooksRun(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('DatabaseMigrationCreatorTest-custom-stub');
        $migrationPath = $directory . '/migrations';
        $stubPath = $directory . '/package.stub';
        $stub = '<?php return new class DummyTable {};';

        $filesystem->ensureDirectoryExists($directory);
        $filesystem->put($stubPath, $stub);
        Date::setTestNow('2026-07-23 12:00:00');

        try {
            $creator = new MigrationCreator($filesystem);
            $creator->afterCreate(function (?string $table, string $path) use ($filesystem): void {
                $this->assertSame('baz', $table);
                $this->assertSame('<?php return new class baz {};', $filesystem->get($path));

                $filesystem->append($path, "\n// Updated by hook.");
            });

            $path = $creator->create('create_bar', $migrationPath, 'baz', stubPath: $stubPath);

            $this->assertSame(
                "<?php return new class baz {};\n// Updated by hook.",
                $filesystem->get($path)
            );
        } finally {
            Date::setTestNow();
            $filesystem->deleteDirectory($directory);
        }
    }

    public function testCustomStubReadFailureDoesNotPublishAMigration(): void
    {
        $exception = new RuntimeException('Unable to read stub.');
        $creator = $this->getCreator([]);
        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturn([]);
        $creator->getFilesystem()->shouldReceive('get')->once()->with('package.stub')->andThrow($exception);
        $creator->getFilesystem()->shouldNotReceive('ensureDirectoryExists');
        $creator->getFilesystem()->shouldNotReceive('replace');

        try {
            $creator->create('create_bar', 'foo', stubPath: 'package.stub');
            $this->fail('Expected the stub read to fail.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPublicationFailureDoesNotFirePostCreateHooks(): void
    {
        $exception = new RuntimeException('Unable to publish migration.');
        $creator = $this->getCreator();
        $creator->expects($this->once())->method('getDatePrefix')->willReturn('foo');
        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturn([]);
        $creator->getFilesystem()->shouldReceive('get')->once()->with('package.stub')->andReturn('return new class');
        $creator->getFilesystem()->shouldReceive('ensureDirectoryExists')->once()->with('foo');
        $creator->getFilesystem()->shouldReceive('replace')->once()->andThrow($exception);

        $hookCalled = false;
        $creator->afterCreate(function () use (&$hookCalled): void {
            $hookCalled = true;
        });

        try {
            $creator->create('create_bar', 'foo', stubPath: 'package.stub');
            $this->fail('Expected migration publication to fail.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertFalse($hookCalled);
    }

    public function testGlobFailureIsReported(): void
    {
        $creator = $this->getCreator([]);
        $creator->getFilesystem()->shouldReceive('glob')->once()->with('foo/*.php')->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read files matching [foo/*.php].');

        $creator->create('create_bar', 'foo');
    }

    public function testDatePrefixUsesTheFrameworkClockWithoutAnActivePath(): void
    {
        $creator = new class(m::mock(Filesystem::class)) extends MigrationCreator {
            public function datePrefix(): string
            {
                return $this->getDatePrefix();
            }
        };

        Date::setTestNow('2026-07-23 12:34:56');

        try {
            $this->assertSame('2026_07_23_123456', $creator->datePrefix());
        } finally {
            Date::setTestNow();
        }
    }

    public function testCollisionFreePrefixesAreIsolatedAcrossConcurrentPaths(): void
    {
        $filesystem = new Filesystem;
        $directory = ParallelTesting::tempDir('DatabaseMigrationCreatorTest-collision-free');
        $firstPath = $directory . '/first';
        $secondPath = $directory . '/second';
        $stubPath = $directory . '/migration.stub';

        $filesystem->ensureDirectoryExists($firstPath);
        $filesystem->ensureDirectoryExists($secondPath);
        $filesystem->put($stubPath, '<?php return new class {};');
        $filesystem->put($firstPath . '/2026_07_23_120000_existing.php', '<?php return new class {};');

        $entered = new Channel(1);
        $continue = new Channel(1);
        $creator = new BlockingMigrationCreator($filesystem, $entered, $continue);

        Date::setTestNow('2026-07-23 12:00:00');

        try {
            $paths = parallel([
                'first' => fn (): string => $creator->create('first', $firstPath, stubPath: $stubPath),
                'second' => function () use ($creator, $secondPath, $stubPath, $entered, $continue): string {
                    if ($entered->pop(5.0) !== true) {
                        throw new RuntimeException('The first migration did not reach the prefix barrier within five seconds.');
                    }

                    $path = $creator->create('second', $secondPath, stubPath: $stubPath);

                    if (! $continue->push(true, 5.0)) {
                        throw new RuntimeException('The first migration did not accept its release signal within five seconds.');
                    }

                    return $path;
                },
            ]);

            $this->assertSame('2026_07_23_120001_first.php', basename($paths['first']));
            $this->assertSame('2026_07_23_120000_second.php', basename($paths['second']));
        } finally {
            Date::setTestNow();
            $filesystem->deleteDirectory($directory);
        }
    }

    protected function getCreator(array $methods = ['getDatePrefix'])
    {
        $files = m::mock(Filesystem::class);
        $customStubs = 'stubs';

        if ($methods === []) {
            return new MigrationCreator($files, $customStubs);
        }

        return $this->getMockBuilder(MigrationCreator::class)
            ->onlyMethods($methods)
            ->setConstructorArgs([$files, $customStubs])
            ->getMock();
    }
}

class BlockingMigrationCreator extends MigrationCreator
{
    protected int $datePrefixCalls = 0;

    public function __construct(
        Filesystem $files,
        protected Channel $entered,
        protected Channel $continue,
    ) {
        parent::__construct($files);
    }

    protected function getDatePrefix(): string
    {
        if (++$this->datePrefixCalls === 1) {
            if (! $this->entered->push(true, 5.0)) {
                throw new RuntimeException('The second migration did not accept the prefix barrier within five seconds.');
            }

            if ($this->continue->pop(5.0) !== true) {
                throw new RuntimeException('The second migration did not release the first migration within five seconds.');
            }
        }

        return parent::getDatePrefix();
    }
}
