<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Log\LogManager;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\TestHandler;
use Psr\Log\AbstractLogger;
use Stringable;
use Swoole\Server;

class LogServiceProviderTest extends TestCase
{
    private TestHandler $handler;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->handler = new TestHandler;

        $config = $app->make('config');

        $config->set('logging.channels.buffered', [
            'driver' => 'monolog',
            'handler' => BufferHandler::class,
            'handler_with' => ['handler' => $this->handler],
        ]);
        $config->set('logging.channels.buffered_stack', [
            'driver' => 'stack',
            'channels' => ['buffered'],
        ]);
        $config->set('logging.channels.custom', [
            'driver' => 'custom',
            'via' => static fn (): AbstractLogger => new class extends AbstractLogger {
                /**
                 * Discard the log message.
                 * @param mixed $level
                 */
                public function log($level, string|Stringable $message, array $context = []): void
                {
                }
            },
        ]);
    }

    public function testBeforeServerForkFlushesBufferedRecordsOnceAndForgetsChannels(): void
    {
        $log = $this->app->make(LogManager::class);
        $stack = $log->channel('buffered_stack');

        $stack->info('Logged while the server booted');

        $this->assertSame([], $this->handler->getRecords());

        $this->dispatchBeforeServerFork();

        $this->assertCount(1, $this->handler->getRecords());
        $this->assertSame('Logged while the server booted', $this->handler->getRecords()[0]->message);
        $this->assertArrayNotHasKey('buffered', $log->getChannels());
        $this->assertArrayNotHasKey('buffered_stack', $log->getChannels());
        $this->assertNotSame($stack, $log->channel('buffered_stack'));
    }

    public function testBeforeServerForkForgetsLoggersWithoutMonologLifecycle(): void
    {
        $log = $this->app->make(LogManager::class);
        $custom = $log->channel('custom');

        $this->dispatchBeforeServerFork();

        $this->assertArrayNotHasKey('custom', $log->getChannels());
        $this->assertNotSame($custom, $log->channel('custom'));
    }

    /**
     * Dispatch the event the server dispatches before forking its processes.
     */
    private function dispatchBeforeServerFork(): void
    {
        $this->app->make('events')->dispatch(new BeforeServerFork(m::mock(Server::class)));
    }
}
