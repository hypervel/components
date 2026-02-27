<?php

declare(strict_types=1);

namespace Hypervel\Signal;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Signal\SignalHandlerInterface;
use Hypervel\Engine\Coroutine;
use Swoole\Server;

class WorkerStopHandler implements SignalHandlerInterface
{
    /**
     * Create a new worker stop handler instance.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Get the signals this handler listens for.
     */
    public function listen(): array
    {
        return [
            [self::WORKER, SIGTERM],
            [self::WORKER, SIGINT],
        ];
    }

    /**
     * Handle the received signal.
     */
    public function handle(int $signal): void
    {
        Coroutine::set([
            'enable_deadlock_check' => false,
        ]);

        if ($signal !== SIGINT) {
            $time = $this->container->make('config')->get('server.settings.max_wait_time', 3);
            sleep($time);
        }

        $this->container->make(Server::class)->stop();
    }
}
