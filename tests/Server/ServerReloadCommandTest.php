<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Commands\ServerReloadCommand;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ServerReloadCommandTest extends TestCase
{
    public function testReloadCommandThrowsCommandExceptionWhenPidFileConfigIsMissing(): void
    {
        $command = new ServerReloadCommand(
            $this->app,
            new Repository([]),
            m::mock(Filesystem::class),
        );
        $command->setHypervel($this->app);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('The config of pid_file is not found.');

        $command->run(new ArrayInput([]), new NullOutput);
    }
}
