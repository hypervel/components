<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Console\MonitorCommand;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMonitorCommandTest extends TestCase
{
    public function testMonitorCommandFailsWhenDatabaseDefaultIsMissing(): void
    {
        $this->app->instance('config', new Repository(['database' => []]));

        $resolver = m::mock(ConnectionResolverInterface::class);

        $command = new MonitorCommand($resolver, m::mock(Dispatcher::class));
        $command->setHypervel($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [database.default] must be a string, NULL given.');

        $command->run(new ArrayInput([]), new NullOutput);
    }

    public function testMonitorCommandPreservesConnectionNameZero(): void
    {
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('threadCount')->once()->andReturn(1);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->once()->with('0')->andReturn($connection);

        $command = new MonitorCommand($resolver, m::mock(Dispatcher::class));
        $command->setHypervel($this->app);

        $this->assertSame(0, $command->run(new ArrayInput(['--databases' => '0']), new NullOutput));
    }
}
