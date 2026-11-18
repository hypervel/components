<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Console\MonitorCommand;
use Hypervel\Database\Events\DatabaseBusy;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMonitorCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testMonitorCommandFailsWhenDatabaseDefaultIsMissing(): void
    {
        $this->app->instance('config', new Repository(['database' => []]));

        $resolver = Double::for(ConnectionResolverInterface::class)->strict();

        $command = new MonitorCommand($resolver, Double::for(Dispatcher::class)->strict());
        $command->setHypervel($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Configuration value for key [database.default] must be a string, NULL given.');

        $command->run(new ArrayInput([]), new NullOutput);
    }

    public function testMonitorCommandPreservesConnectionNameZero(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('threadCount')->returns(1);

        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->expects('connection')->with('0')->returns($connection);

        $command = new MonitorCommand($resolver, Double::for(Dispatcher::class)->strict());
        $command->setHypervel($this->app);

        $this->assertSame(0, $command->run(new ArrayInput(['--databases' => '0']), new NullOutput));
    }

    public function testBusyEventIsNotDispatchedWithoutListeners(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('threadCount')->returns(2);
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->expects('connection')->with('primary')->returns($connection);
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(DatabaseBusy::class)->returns(false);
        $events->expects('dispatch')->never();
        $command = new MonitorCommand($resolver, $events);
        $command->setHypervel($this->app);

        $this->assertSame(0, $command->run(new ArrayInput([
            '--databases' => 'primary',
            '--max' => '1',
        ]), new NullOutput));
    }

    public function testBusyEventIsDispatchedWithAListener(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('threadCount')->returns(2);
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $resolver->expects('connection')->with('primary')->returns($connection);
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(DatabaseBusy::class)->returns(true);
        $events->expects('dispatch')->with(Argument::satisfies(
            static fn (DatabaseBusy $event): bool => $event->connectionName === 'primary'
                && $event->connections === 2
        ));
        $command = new MonitorCommand($resolver, $events);
        $command->setHypervel($this->app);

        $this->assertSame(0, $command->run(new ArrayInput([
            '--databases' => 'primary',
            '--max' => '1',
        ]), new NullOutput));
    }
}
