<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Console\ForgetCommand;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ForgetCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testForgetKeyFromDefaultStore(): void
    {
        $cacheManager = Double::for(CacheManager::class);
        $repository = Double::for(Repository::class);

        $cacheManager->expects('store')->with(null)->returns($repository);
        $repository->expects('forget')->with('my-key');

        $command = new ForgetCommand($cacheManager);
        $command->setHypervel(new Application);

        $output = new BufferedOutput;

        $this->assertSame(0, $command->run(new ArrayInput(['key' => 'my-key']), $output));
        $this->assertStringContainsString(
            'key has been removed from the cache',
            $output->fetch()
        );
    }

    public function testForgetKeyFromSpecifiedStore(): void
    {
        $cacheManager = Double::for(CacheManager::class);
        $repository = Double::for(Repository::class);

        $cacheManager->expects('store')->with('redis')->returns($repository);
        $repository->expects('forget')->with('my-key');

        $command = new ForgetCommand($cacheManager);
        $command->setHypervel(new Application);

        $output = new BufferedOutput;

        $this->assertSame(0, $command->run(new ArrayInput(['key' => 'my-key', 'store' => 'redis']), $output));
        $this->assertStringContainsString(
            'key has been removed from the cache',
            $output->fetch()
        );
    }
}
