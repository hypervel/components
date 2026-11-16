<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Console\ClearCommand;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ClearCommandTest extends TestCase
{
    use VerifiesDoubles;

    private ClearCommand $command;

    private CacheManager&DoubleInterface $cacheManager;

    private Filesystem&DoubleInterface $files;

    private Repository $cacheRepository;

    /**
     * Set up the command and its dependencies.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application;
        $app->instance('path.storage', __DIR__);

        $this->cacheManager = Double::for(CacheManager::class);
        $this->files = Double::for(Filesystem::class);
        $this->cacheRepository = new Repository(new ArrayStore);
        $this->command = new ClearCommand($this->cacheManager, $this->files);
        $this->command->setHypervel($app);
    }

    public function testClearWithNoStoreArgument(): void
    {
        $this->files->expects('deleteDirectory');

        $this->cacheRepository->put('foo', 'bar');
        $this->cacheManager->expects('store')->with(null)->returns($this->cacheRepository);

        $this->assertSame(SymfonyCommand::SUCCESS, $this->runCommand($this->command));

        $this->assertNull($this->cacheRepository->get('foo'));
    }

    public function testClearWillFailWhenFlushFails(): void
    {
        $store = Double::for(Store::class);
        $store->expects('flush')->returns(false);
        $this->cacheManager->expects('store')->with(null)->returns(new Repository($store));
        $this->files->expects('deleteDirectory');

        $this->assertSame(SymfonyCommand::FAILURE, $this->runCommand($this->command));
    }

    #[DataProvider('prohibitedOptions')]
    public function testProhibitedCommandDoesNotClearCacheOrLocks(array $options): void
    {
        ClearCommand::prohibit();
        $this->cacheManager->expects('store')->never();
        $this->files->expects('deleteDirectory')->never();

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

        $this->cacheRepository->put('foo', 'bar');
        $this->cacheManager->expects('store')->with('foo')->returns($this->cacheRepository);

        $this->runCommand($this->command, ['store' => 'foo']);

        $this->assertNull($this->cacheRepository->get('foo'));
    }

    public function testClearWithInvalidStoreArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cacheManager->expects('store')->with('bar')->throws(new InvalidArgumentException);

        $this->runCommand($this->command, ['store' => 'bar']);
    }

    public function testClearWithTagsOption(): void
    {
        $this->files->expects('deleteDirectory');

        $this->cacheRepository->tags(['foo', 'bar'])->put('tagged', 'value');
        $this->cacheRepository->put('untagged', 'value');
        $this->cacheManager->expects('store')->with(null)->returns($this->cacheRepository);

        $this->runCommand($this->command, ['--tags' => 'foo,bar']);

        $this->assertNull($this->cacheRepository->tags(['foo', 'bar'])->get('tagged'));
        $this->assertSame('value', $this->cacheRepository->get('untagged'));
    }

    public function testClearWithStoreArgumentAndTagsOption(): void
    {
        $this->files->expects('deleteDirectory');

        $this->cacheRepository->tags(['foo'])->put('tagged', 'value');
        $this->cacheRepository->put('untagged', 'value');
        $this->cacheManager->expects('store')->with('redis')->returns($this->cacheRepository);

        $this->runCommand($this->command, ['store' => 'redis', '--tags' => 'foo']);

        $this->assertNull($this->cacheRepository->tags(['foo'])->get('tagged'));
        $this->assertSame('value', $this->cacheRepository->get('untagged'));
    }

    // REMOVED: real-time facade cleanup tests; Hypervel supports explicit facades only.

    public function testClearWillFlushAopProxyDirectory(): void
    {
        $this->cacheManager->expects('store')->with(null)->returns($this->cacheRepository);

        $this->files->expects('deleteDirectory')->with($this->command->getHypervel()->bootstrapPath('cache/aop'));

        $this->runCommand($this->command);
    }

    public function testClearLocksWithNoStoreArgument(): void
    {
        $store = $this->lockFlushingStore();
        $store->expects('flushLocks')->returns(true);
        $store->expects('flush')->never();
        $this->cacheManager->expects('store')->with(null)->returns(new Repository($store));

        $this->files->expects('deleteDirectory')->never();

        $this->assertSame(0, $this->runCommand($this->command, ['--locks' => true]));
    }

    public function testClearLocksWithStoreArgument(): void
    {
        $store = $this->lockFlushingStore();
        $store->expects('flushLocks')->returns(true);
        $store->expects('flush')->never();
        $this->cacheManager->expects('store')->with('redis')->returns(new Repository($store));

        $this->assertSame(0, $this->runCommand($this->command, ['store' => 'redis', '--locks' => true]));
    }

    public function testClearLocksCannotBeUsedWithTags(): void
    {
        $this->cacheManager->expects('store')->never();

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true, '--tags' => 'foo']));
    }

    public function testClearLocksWillFailWhenNotSupportedByStore(): void
    {
        $store = Double::for(Store::class);
        $store->expects('flush')->never();
        $this->cacheManager->expects('store')->with(null)->returns(new Repository($store));

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true]));
    }

    public function testClearLocksWillFailWhenFlushLocksFails(): void
    {
        $store = $this->lockFlushingStore();
        $store->expects('flushLocks')->returns(false);
        $store->expects('flush')->never();
        $this->cacheManager->expects('store')->with(null)->returns(new Repository($store));

        $this->assertSame(1, $this->runCommand($this->command, ['--locks' => true]));
    }

    /**
     * Create a store that supports clearing locks.
     */
    protected function lockFlushingStore(): Store&CanFlushLocks&DoubleInterface
    {
        $store = Double::for(Store::class, CanFlushLocks::class);
        $store->allows('supportsFlushingLocks')->returns(true);

        return $store;
    }

    /**
     * Run the cache clear command with the given input.
     */
    protected function runCommand(SymfonyCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}
