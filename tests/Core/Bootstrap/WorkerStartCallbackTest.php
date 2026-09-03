<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Core\Bootstrap\WorkerStartCallback;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Core\Events\MainWorkerStart;
use Hypervel\Core\Events\OtherWorkerStart;
use Hypervel\Core\Logger\StdoutLogger;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LogLevel;
use Swoole\Server;
use Symfony\Component\Console\Output\BufferedOutput;

class WorkerStartCallbackTest extends TestCase
{
    public function testLifecycleEventsAndStartupLoggingRemainOrdered(): void
    {
        $sequence = [];
        $server = m::mock(Server::class);
        $server->taskworker = false;

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(BeforeWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MainWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('hasListeners')->once()->with(AfterWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(m::type(BeforeWorkerStart::class))
            ->andReturnUsing(function () use (&$sequence): void {
                $sequence[] = 'before';
            });
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(m::type(MainWorkerStart::class))
            ->andReturnUsing(function () use (&$sequence): void {
                $sequence[] = 'main';
            });
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->ordered()
            ->with(m::type(AfterWorkerStart::class))
            ->andReturnUsing(function () use (&$sequence): void {
                $sequence[] = 'after';
            });

        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('Worker#0 started.')
            ->andReturnUsing(function () use (&$sequence): void {
                $sequence[] = 'started';
            });

        $coordinator = CoordinatorManager::until(Constants::WORKER_START);

        (new WorkerStartCallback($dispatcher, $logger))->onWorkerStart($server, 0);

        $this->assertSame(['before', 'main', 'started', 'after'], $sequence);
        $this->assertTrue($coordinator->isClosing());
    }

    public function testWorkerStartsAndResumesCoordinationWithoutLifecycleListeners(): void
    {
        $server = m::mock(Server::class);
        $server->taskworker = false;

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(BeforeWorkerStart::class)->andReturnFalse();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MainWorkerStart::class)->andReturnFalse();
        $dispatcher->shouldReceive('hasListeners')->once()->with(AfterWorkerStart::class)->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');

        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('Worker#0 started.');
        $coordinator = CoordinatorManager::until(Constants::WORKER_START);

        (new WorkerStartCallback($dispatcher, $logger))->onWorkerStart($server, 0);

        $this->assertTrue($coordinator->isClosing());
    }

    public function testStartupLoggingUsesConfigurationRefreshedDuringBeforeWorkerStart(): void
    {
        $config = new Repository([
            'app' => ['stdout_log' => ['level' => [LogLevel::ERROR], 'format' => 'line']],
        ]);
        $output = new BufferedOutput;
        $logger = new StdoutLogger($config, $output);
        $server = m::mock(Server::class);
        $server->taskworker = false;

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(BeforeWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('hasListeners')->once()->with(MainWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('hasListeners')->once()->with(AfterWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(BeforeWorkerStart::class))
            ->andReturnUsing(function () use ($config): void {
                $config->set('app.stdout_log.level', [LogLevel::INFO]);
                $config->set('app.stdout_log.format', 'json');
            });
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(MainWorkerStart::class));
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(AfterWorkerStart::class));

        (new WorkerStartCallback($dispatcher, $logger))->onWorkerStart($server, 0);

        $entry = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Worker#0 started.', $entry['message']);
    }

    public function testCustomLoggerOwnsItsConfiguration(): void
    {
        $server = m::mock(Server::class);
        $server->taskworker = true;

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->once()->with(BeforeWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('hasListeners')->once()->with(OtherWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('hasListeners')->once()->with(AfterWorkerStart::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(BeforeWorkerStart::class));
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(OtherWorkerStart::class));
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(AfterWorkerStart::class));

        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('TaskWorker#2 started.');

        (new WorkerStartCallback($dispatcher, $logger))->onWorkerStart($server, 2);
    }
}
