<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Testbench\TestCase;
use Hypervel\Watcher\ServerRestartStrategy;
use InvalidArgumentException;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class ServerRestartStrategyTest extends TestCase
{
    public function testConstructorThrowsWhenPidFileNotConfigured()
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '', 'daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => 'artisan serve'],
        ]));

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('The config of pid_file is not found.');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    public function testConstructorThrowsWhenDaemonizeIsTrue()
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => true]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => 'artisan serve'],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Please set `server.settings.daemonize` to false');

        new ServerRestartStrategy($this->app, new NullOutput);
    }

    public function testConstructorSucceedsWithValidConfig()
    {
        $this->app->instance('config', new Repository([
            'server' => ['settings' => ['pid_file' => '/tmp/test.pid', 'daemonize' => false]],
            'watcher' => ['bin' => PHP_BINARY, 'command' => 'artisan serve'],
        ]));

        $strategy = new ServerRestartStrategy($this->app, new NullOutput);

        $this->assertInstanceOf(ServerRestartStrategy::class, $strategy);
    }
}
