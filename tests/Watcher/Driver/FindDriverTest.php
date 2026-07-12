<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher\Driver;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Watcher\Fixtures\FindDriverStub;
use Hypervel\Watcher\Driver\FindDriver;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;
use InvalidArgumentException;

class FindDriverTest extends TestCase
{
    public function testWatch(): void
    {
        $option = new Option(
            driver: FindDriver::class,
            watchPaths: [
                new WatchPath('/tmp', WatchPathType::Directory),
                new WatchPath('.env', WatchPathType::File),
            ],
            scanInterval: 1,
        );

        $channel = new Channel(10);

        try {
            $driver = new FindDriverStub($option);
            $finished = new WaitGroup(1);
            Coroutine::create(function () use ($channel, $driver, $finished): void {
                try {
                    $driver->watch($channel);
                } finally {
                    $finished->done();
                }
            });

            $this->assertSame('.env', $channel->pop($option->getScanIntervalSeconds() + 0.1));
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'find not exists')) {
                $this->markTestSkipped();
            }
            throw $e;
        } finally {
            if (isset($driver)) {
                $driver->stop();
            }
            if (isset($finished)) {
                $this->assertTrue($finished->wait(0.1));
            }
            $channel->close();
        }
    }

    public function testFindEscapesEveryTargetPath(): void
    {
        $option = new Option(driver: FindDriver::class, watchPaths: [], scanInterval: 1);
        $driver = new class($option) extends FindDriver {
            public string $capturedCommand = '';

            protected function exec(string $command): array
            {
                if (str_starts_with($command, 'which ')) {
                    return ['code' => 0, 'output' => '/usr/bin/find'];
                }

                if ($command === 'find --help') {
                    return ['code' => 0, 'output' => 'GNU find'];
                }

                $this->capturedCommand = $command;

                return ['code' => 0, 'output' => ''];
            }

            public function findForTest(array $targets): void
            {
                $this->find([], $targets, '-0.10');
            }
        };
        $targets = ['/tmp/path with spaces', "/tmp/path'quoted", '/tmp/$(ignored);touch nope'];

        $driver->findForTest($targets);

        $this->assertSame(
            ($driver->isDarwin() ? 'gfind' : 'find') . ' '
                . implode(' ', array_map(escapeshellarg(...), $targets))
                . ' -mmin -0.10 -type f -print',
            $driver->capturedCommand,
        );
    }
}
