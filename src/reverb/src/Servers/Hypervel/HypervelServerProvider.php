<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Core\Swoole\StripedLock;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Contracts\ServerProvider;
use Hypervel\Reverb\Protocols\Pusher\PusherPubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisPubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Support\Facades\Redis;
use InvalidArgumentException;
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
        protected Container $app,
        protected array $config,
    ) {
        $this->publishesEvents = $this->config['scaling']['enabled'];
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->shouldPublishEvents()) {
            $this->validateScalingRedisConnection();

            $this->app->singleton(SharedState::class, fn () => new RedisSharedState(
                $this->scalingRedisConnection(),
            ));
        } else {
            // Bound lazily so processes that never start the server allocate no shared
            // memory. boot() resolves it on BeforeServerStart, so the tables and the
            // striped locks exist before the fork and are shared by every worker.
            $this->app->singleton(SharedState::class, function (): SwooleTableSharedState {
                $table = new Table($this->config['swoole_shared_state']['rows']);
                $table->column('count', Table::TYPE_INT);
                $table->create();

                $lockTable = new Table($this->config['swoole_shared_state']['lock_rows']);
                $lockTable->column('locked_at', Table::TYPE_FLOAT);
                $lockTable->create();

                return new SwooleTableSharedState($table, $lockTable, new StripedLock);
            });
        }

        $this->app->singleton(
            PubSubIncomingMessageHandler::class,
            fn () => new PusherPubSubIncomingMessageHandler,
        );

        $this->app->singleton(PubSubProvider::class, fn ($app) => new RedisPubSubProvider(
            $app->make(PubSubIncomingMessageHandler::class),
            $this->scalingRedisConnection(),
            $this->config['scaling']['channel'],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        if ($this->shouldNotPublishEvents()) {
            $events->listen(BeforeServerStart::class, function (): void {
                $this->app->make(SharedState::class);
            });
        }

        if ($this->subscribesToEvents()) {
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
     *
     * Tests only. The flag persists on the cached server provider for the
     * worker lifetime and cannot be turned back off.
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
     * Uses the configured scaling connection so subscribe, publish, and
     * shared state share the same prefix, authentication, and endpoint.
     */
    protected function scalingRedisConnection(): RedisProxy
    {
        $connectionName = (string) $this->config['scaling']['connection'];

        return Redis::connection($connectionName);
    }

    /**
     * Ensure the scaling connection provides exact pub/sub subscriber counts.
     */
    protected function validateScalingRedisConnection(): void
    {
        $connectionName = (string) $this->config['scaling']['connection'];
        $connection = $this->app->make(RedisConfig::class)->connectionConfig($connectionName);

        if ((bool) ($connection['cluster']['enabled'] ?? false)) {
            throw new InvalidArgumentException(sprintf(
                "Reverb scaling does not support Redis Cluster. Disable 'reverb.servers.reverb.scaling.enabled' or set 'database.redis.%s.cluster.enabled' to false.",
                $connectionName,
            ));
        }
    }
}
