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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Psr\Log\LogLevel;
use Swoole\Server;
use Symfony\Component\Console\Output\BufferedOutput;

class WorkerStartCallbackTest extends TestCase
{
    use VerifiesDoubles;

    public function testLifecycleEventsAndStartupLoggingRemainOrdered(): void
    {
        $sequence = [];
        $server = Double::for(Server::class);
        $server->taskworker = false;

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(BeforeWorkerStart::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(MainWorkerStart::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(AfterWorkerStart::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->ordered()
            ->with(Argument::type(BeforeWorkerStart::class))
            ->resolves(function () use (&$sequence): void {
                $sequence[] = 'before';
            });
        $dispatcher->expects('dispatch')
            ->ordered()
            ->with(Argument::type(MainWorkerStart::class))
            ->resolves(function () use (&$sequence): void {
                $sequence[] = 'main';
            });
        $dispatcher->expects('dispatch')
            ->ordered()
            ->with(Argument::type(AfterWorkerStart::class))
            ->resolves(function () use (&$sequence): void {
                $sequence[] = 'after';
            });

        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('info')
            ->with('Worker#0 started.')
            ->resolves(function () use (&$sequence): void {
                $sequence[] = 'started';
            });

        $coordinator = CoordinatorManager::until(Constants::WORKER_START);

        (new WorkerStartCallback($dispatcher, $logger))->onWorkerStart($server, 0);

        $this->assertSame(['before', 'main', 'started', 'after'], $sequence);
        $this->assertTrue($coordinator->isClosing());
    }

    public function testWorkerStartsAndResumesCoordinationWithoutLifecycleListeners(): void
    {
        $server = Double::for(Server::class);
        $server->taskworker = false;

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(BeforeWorkerStart::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(MainWorkerStart::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(AfterWorkerStart::class)->returns(false);
        $dispatcher->expects('dispatch')->never();

        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('info')->with('Worker#0 started.');
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
        $server = Double::for(Server::class);
        $server->taskworker = false;

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(BeforeWorkerStart::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(MainWorkerStart::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(AfterWorkerStart::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(BeforeWorkerStart::class))
            ->resolves(function () use ($config): void {
                $config->set('app.stdout_log.level', [LogLevel::INFO]);
                $config->set('app.stdout_log.format', 'json');
            });
        $dispatcher->expects('dispatch')->with(Argument::type(MainWorkerStart::class));
        $dispatcher->expects('dispatch')->with(Argument::type(AfterWorkerStart::class));

        (new WorkerStartCallback($dispatcher, $logger))->onWorkerStart($server, 0);

        $entry = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Worker#0 started.', $entry['message']);
    }

    public function testCustomLoggerOwnsItsConfiguration(): void
    {
        $server = Double::for(Server::class);
        $server->taskworker = true;

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(BeforeWorkerStart::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(OtherWorkerStart::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(AfterWorkerStart::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(BeforeWorkerStart::class));
        $dispatcher->expects('dispatch')->with(Argument::type(OtherWorkerStart::class));
        $dispatcher->expects('dispatch')->with(Argument::type(AfterWorkerStart::class));

        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('info')->with('TaskWorker#2 started.');

        (new WorkerStartCallback($dispatcher, $logger))->onWorkerStart($server, 2);
    }
}
