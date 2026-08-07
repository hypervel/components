<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Listeners;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Coordinator\Timer;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\SwooleStore;
use InvalidArgumentException;
use Throwable;

class RegisterPruneTimer
{
    /**
     * Create a new Swoole rate limiter prune-timer listener.
     */
    public function __construct(
        protected Repository $config,
        protected RateLimiter $rateLimiter,
        protected Timer $timer,
    ) {
    }

    /**
     * Register a prune timer for every configured Swoole store.
     */
    public function handle(AfterWorkerStart $event): void
    {
        if ($event->workerId !== 0 || $event->server->taskworker) {
            return;
        }

        $storeIntervals = [];

        foreach ($this->config->array('rate-limiter.stores') as $name => $config) {
            if (! is_array($config) || ($config['driver'] ?? null) !== 'swoole') {
                continue;
            }

            $name = (string) $name;
            $interval = $this->config->integer("rate-limiter.stores.{$name}.prune_interval");

            if ($interval <= 0) {
                throw new InvalidArgumentException(
                    "Configuration value for key [rate-limiter.stores.{$name}.prune_interval] must be greater than zero."
                );
            }

            $storeIntervals[$name] = $interval;
        }

        $registrations = [];

        foreach ($storeIntervals as $name => $interval) {
            $registrations[] = [
                'interval' => $interval,
                'store' => $this->store($name),
            ];
        }

        $timerIds = [];

        try {
            foreach ($registrations as $registration) {
                $timerIds[] = $this->timer->tick(
                    $registration['interval'],
                    fn (): int => $registration['store']->maintain(),
                );
            }
        } catch (Throwable $throwable) {
            for ($index = count($timerIds) - 1; $index >= 0; --$index) {
                try {
                    $this->timer->clear($timerIds[$index]);
                } catch (Throwable) {
                    // Preserve the timer registration failure.
                }
            }

            throw $throwable;
        }
    }

    /**
     * Get a configured Swoole rate limiter store.
     */
    protected function store(string $name): SwooleStore
    {
        /** @var SwooleStore */
        return $this->rateLimiter->store($name)->getStore();
    }
}
