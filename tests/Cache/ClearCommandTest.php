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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ClearCommandTest extends TestCase
{
    private ClearCommandTestStub $command;

    private CacheManager|m\MockInterface $cacheManager;

    private Filesystem|m\MockInterface $files;

    private Repository|m\MockInterface $cacheRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application;
        $app['path.storage'] = __DIR__;

        $this->cacheManager = m::mock(CacheManager::class);
        $this->files = m::mock(Filesystem::class);
        $this->cacheRepository = m::mock(Repository::class);
        $this->command = new ClearCommandTestStub($this->cacheManager, $this->files);
        $this->command->setHypervel($app);
    }

    public function testClearWithNoStoreArgument()
    {
        $this->files->shouldReceive('deleteDirectory')->once();

        $this->cacheManager->shouldReceive('store')->once()->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flush')->once();

        $this->runCommand($this->command);
    }

    public function testClearWithStoreArgument()
    {
        $this->files->shouldReceive('deleteDirectory')->once();

        $this->cacheManager->shouldReceive('store')->once()->with('foo')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flush')->once();

        $this->runCommand($this->command, ['store' => 'foo']);
    }

    public function testClearWithInvalidStoreArgument()
    {
        $this->cacheManager->shouldReceive('store')->once()->with('bar')->andThrow(InvalidArgumentException::class);
        $this->cacheRepository->shouldReceive('flush')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->runCommand($this->command, ['store' => 'bar']);
    }

    public function testClearWithTagsOption()
    {
        $this->files->shouldReceive('deleteDirectory')->once();

        $this->cacheManager->shouldReceive('store')->once()->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('tags')->once()->with(['foo', 'bar'])->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flush')->once();

        $this->runCommand($this->command, ['--tags' => 'foo,bar']);
    }

    public function testClearWithStoreArgumentAndTagsOption()
    {
        $this->files->shouldReceive('deleteDirectory')->once();

        $this->cacheManager->shouldReceive('store')->once()->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('tags')->once()->with(['foo'])->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flush')->once();

        $this->runCommand($this->command, ['store' => 'redis', '--tags' => 'foo']);
    }

    public function testClearWillFlushAopProxyDirectory()
    {
        $this->cacheManager->shouldReceive('store')->once()->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flush')->once();

        $this->files->shouldReceive('deleteDirectory')->with(storage_path('framework/aop'))->once();

        $this->runCommand($this->command);
    }

    public function testClearLocksWithNoStoreArgument()
    {
        $this->cacheManager->shouldReceive('store')->once()->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flushLocks')->once()->andReturn(true);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->files->shouldNotReceive('exists');
        $this->files->shouldNotReceive('files');
        $this->files->shouldNotReceive('delete');

        $this->assertSame(0, $this->runCommand($this->command, ['--locks' => true]));
    }

    public function testClearLocksWithStoreArgument()
    {
        $this->cacheManager->shouldReceive('store')->once()->with('redis')->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flushLocks')->once()->andReturn(true);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->assertSame(0, $this->runCommand($this->command, ['store' => 'redis', '--locks' => true]));
    }

    public function testClearLocksCannotBeUsedWithTags()
    {
        $this->cacheManager->shouldNotReceive('store');
        $this->cacheRepository->shouldNotReceive('flush');
        $this->cacheRepository->shouldNotReceive('flushLocks');

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true, '--tags' => 'foo']));
    }

    public function testClearLocksWillFailWhenNotSupportedByStore()
    {
        $this->cacheManager->shouldReceive('store')->once()->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flushLocks')->once()->andThrow(new BadMethodCallException);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true]));
    }

    public function testClearLocksWillFailWhenFlushLocksFails()
    {
        $this->cacheManager->shouldReceive('store')->once()->with(null)->andReturn($this->cacheRepository);
        $this->cacheRepository->shouldReceive('flushLocks')->once()->andReturn(false);
        $this->cacheRepository->shouldNotReceive('flush');

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true]));
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ClearCommandTestStub extends ClearCommand
{
    public function call(\Symfony\Component\Console\Command\Command|string $command, array $arguments = []): int
    {
        return 0;
    }
}
