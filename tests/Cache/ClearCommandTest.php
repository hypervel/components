<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use BadMethodCallException;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Console\ClearCommand;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ClearCommandTest extends TestCase
{
    private ClearCommand $command;

    private CacheManager&m\MockInterface $cacheManager;

    private Filesystem&m\MockInterface $files;

    private Repository&m\MockInterface $cacheRepository;

    /**
     * Set up the command and its dependencies.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application;
        $app->instance('path.storage', __DIR__);

        $this->cacheManager = m::mock(CacheManager::class);
        $this->files = m::mock(Filesystem::class);
        $this->cacheRepository = m::mock(Repository::class);
        $this->command = new ClearCommand($this->cacheManager, $this->files);
        $this->command->setHypervel($app);
    }

    #[DataProvider('flushResults')]
    public function testClearWithNoStoreArgument(bool $successful, int $exitCode): void
    {
        $this->files->expects('deleteDirectory');

        $this->cacheManager->expects('store')->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flush')->andReturn($successful);

        $this->assertSame($exitCode, $this->runCommand($this->command));
    }

    /**
     * Provide successful and failed cache flush results.
     */
    public static function flushResults(): array
    {
        return [
            [true, SymfonyCommand::SUCCESS],
            [false, SymfonyCommand::FAILURE],
        ];
    }

    #[DataProvider('prohibitedOptions')]
    public function testProhibitedCommandDoesNotClearCacheOrLocks(array $options): void
    {
        ClearCommand::prohibit();
        $this->cacheManager->shouldNotReceive('store');
        $this->files->shouldNotReceive('deleteDirectory');

        $this->assertSame(SymfonyCommand::FAILURE, $this->runCommand($this->command, $options));
    }

    /**
     * Provide the cache clearing modes.
     */
    public static function prohibitedOptions(): array
    {
        return [[[]], [['--locks' => true]]];
    }

    public function testClearWithStoreArgument(): void
    {
        $this->files->expects('deleteDirectory');

        $this->cacheManager->expects('store')->with('foo')->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flush')->andReturnTrue();

        $this->runCommand($this->command, ['store' => 'foo']);
    }

    public function testClearWithInvalidStoreArgument(): void
    {
        $this->cacheManager->expects('store')->with('bar')->andThrow(InvalidArgumentException::class);
        $this->cacheRepository->shouldReceive('flush')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->runCommand($this->command, ['store' => 'bar']);
    }

    public function testClearWithTagsOption(): void
    {
        $this->files->expects('deleteDirectory');

        $this->cacheManager->expects('store')->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('tags')->with(['foo', 'bar'])->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flush')->andReturnTrue();

        $this->runCommand($this->command, ['--tags' => 'foo,bar']);
    }

    public function testClearWithStoreArgumentAndTagsOption(): void
    {
        $this->files->expects('deleteDirectory');

        $this->cacheManager->expects('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('tags')->with(['foo'])->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flush')->andReturnTrue();

        $this->runCommand($this->command, ['store' => 'redis', '--tags' => 'foo']);
    }

    // REMOVED: real-time facade cleanup tests; Hypervel supports explicit facades only.

    public function testClearWillFlushAopProxyDirectory(): void
    {
        $this->cacheManager->expects('store')->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flush')->andReturnTrue();

        $this->files->expects('deleteDirectory')->with(storage_path('framework/aop'));

        $this->runCommand($this->command);
    }

    public function testClearLocksWithNoStoreArgument(): void
    {
        $this->cacheManager->expects('store')->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flushLocks')->andReturn(true);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->files->shouldNotReceive('deleteDirectory');

        $this->assertSame(0, $this->runCommand($this->command, ['--locks' => true]));
    }

    public function testClearLocksWithStoreArgument(): void
    {
        $this->cacheManager->expects('store')->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flushLocks')->andReturn(true);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->assertSame(0, $this->runCommand($this->command, ['store' => 'redis', '--locks' => true]));
    }

    public function testClearLocksCannotBeUsedWithTags(): void
    {
        $this->cacheManager->shouldNotReceive('store');
        $this->cacheRepository->shouldNotReceive('flush');
        $this->cacheRepository->shouldNotReceive('flushLocks');

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true, '--tags' => 'foo']));
    }

    public function testClearLocksWillFailWhenNotSupportedByStore(): void
    {
        $this->cacheManager->expects('store')->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flushLocks')->andThrow(new BadMethodCallException);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true]));
    }

    public function testClearLocksWillFailWhenFlushLocksFails(): void
    {
        $this->cacheManager->expects('store')->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->expects('flushLocks')->andReturn(false);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true]));
    }

    /**
     * Run the cache clear command with the given input.
     */
    protected function runCommand(SymfonyCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}
