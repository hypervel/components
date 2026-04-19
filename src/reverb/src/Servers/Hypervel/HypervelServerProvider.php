<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Reverb\Contracts\ServerProvider;
use Hypervel\Reverb\Protocols\Pusher\PusherPubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisPubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Support\Facades\Redis;
use Swoole\Table;

class HypervelServerProvider extends ServerProvider
{
    /**
     * Whether the server should publish events to Redis pub/sub.
     */
    protected bool $publishesEvents;

    /**
     * Create a new server provider instance.
     */
    public function __construct(
        protected \Hypervel\Contracts\Container\Container $app,
        protected array $config,
    ) {
        $this->publishesEvents = (bool) ($this->config['scaling']['enabled'] ?? false);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->shouldPublishEvents()) {
            $this->app->singleton(SharedState::class, fn () => new RedisSharedState(
                $this->scalingRedisConnection(),
            ));
        } else {
            // Eagerly create the full SwooleTableSharedState before fork.
            // Both the Swoole Table and the striped Atomic locks must exist
            // in the main process so they're shared across all workers via
            // copy-on-write. Using instance() instead of singleton() ensures
            // the object is created now, not lazily in a worker.
            $rows = (int) ($this->config['swoole_shared_state']['rows'] ?? 65536);
            $table = new Table($rows);
            $table->column('count', Table::TYPE_INT);
            $table->create();

            $lockRows = (int) ($this->config['swoole_shared_state']['lock_rows'] ?? 8192);
            $lockTable = new Table($lockRows);
            $lockTable->column('locked_at', Table::TYPE_FLOAT);
            $lockTable->create();

            $this->app->instance(SharedState::class, new SwooleTableSharedState($table, $lockTable));
        }

        $this->app->singleton(
            PubSubIncomingMessageHandler::class,
            fn () => new PusherPubSubIncomingMessageHandler,
        );

        $this->app->singleton(PubSubProvider::class, fn ($app) => new RedisPubSubProvider(
            $app->make(PubSubIncomingMessageHandler::class),
            $this->scalingRedisConnection(),
            $this->config['scaling']['channel'] ?? 'reverb',
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->subscribesToEvents()) {
            $events = $this->app->make('events');

            $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event) {
                if ($event->server->taskworker) {
                    return;
                }

                $this->app->make(PubSubProvider::class)->connect();
            });
        }
    }

    /**
     * Enable publishing of events.
     */
    public function withPublishing(): void
    {
        $this->publishesEvents = true;
    }

    /**
     * Determine whether the server should publish events.
     */
    public function shouldPublishEvents(): bool
    {
        return $this->publishesEvents;
    }

    /**
     * Get the Redis connection for scaling operations.
     *
     * Uses the connection name from the scaling config, defaulting to 'reverb'.
     * This ensures subscribe, publish, and shared-state all use the same
     * Redis connection with consistent prefix, auth, and host settings.
     */
    protected function scalingRedisConnection(): \Hypervel\Redis\RedisProxy
    {
        $connectionName = (string) ($this->config['scaling']['connection'] ?? 'reverb');

        return Redis::connection($connectionName);
    }
}
