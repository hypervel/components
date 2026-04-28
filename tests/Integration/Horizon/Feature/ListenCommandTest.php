<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Console\HorizonRestartStrategy;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Hypervel\Watcher\RestartStrategy;
use InvalidArgumentException;
use RuntimeException;

class ListenCommandTest extends IntegrationTestCase
{
    public function testListenCommandRequiresWatchConfiguration()
    {
        config(['horizon.watch' => [], 'watcher' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('List of directories / files to watch not found.');

        $this->artisan('horizon:listen');
    }

    public function testListenCommandRequiresWatchConfigurationToBeSet()
    {
        config(['horizon.watch' => null, 'watcher' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('List of directories / files to watch not found.');

        $this->artisan('horizon:listen');
    }

    public function testListenCommandRequiresWatchConfigurationKeyToExist()
    {
        $config = config('horizon');
        unset($config['watch']);
        config(['horizon' => $config, 'watcher' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('List of directories / files to watch not found.');

        $this->artisan('horizon:listen');
    }

    public function testListenCommandFallsBackToWatcherConfig()
    {
        $config = config('horizon');
        unset($config['watch']);
        config(['horizon' => $config]);
        config(['watcher.watch' => ['app']]);

        // Bind a fake restart strategy whose start() throws a sentinel exception.
        // If config validation passes, the Watcher calls strategy->start() before
        // entering its infinite loop. Catching the sentinel proves we got past
        // config validation without hitting InvalidArgumentException.
        $this->app->bind(HorizonRestartStrategy::class, function () {
            return new class implements RestartStrategy {
                public function start(): void
                {
                    throw new RuntimeException('__sentinel_start_called__');
                }

                public function restart(): void
                {
                }
            };
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('__sentinel_start_called__');

        $this->artisan('horizon:listen');
    }

    public function testListenCommandFallsBackToWatcherConfigWhenHorizonWatchIsEmpty()
    {
        config(['horizon.watch' => []]);
        config(['watcher.watch' => ['app']]);

        $this->app->bind(HorizonRestartStrategy::class, function () {
            return new class implements RestartStrategy {
                public function start(): void
                {
                    throw new RuntimeException('__sentinel_start_called__');
                }

                public function restart(): void
                {
                }
            };
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('__sentinel_start_called__');

        $this->artisan('horizon:listen');
    }

    public function testListenCommandFallsBackToWatcherConfigWhenHorizonWatchIsNull()
    {
        config(['horizon.watch' => null]);
        config(['watcher.watch' => ['app']]);

        $this->app->bind(HorizonRestartStrategy::class, function () {
            return new class implements RestartStrategy {
                public function start(): void
                {
                    throw new RuntimeException('__sentinel_start_called__');
                }

                public function restart(): void
                {
                }
            };
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('__sentinel_start_called__');

        $this->artisan('horizon:listen');
    }
}
