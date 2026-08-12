<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisManager;
use Hypervel\Sentry\Features\Concerns\ResolvesEventOrigin;
use Hypervel\Sentry\Features\Concerns\ResolvesSessionKey;
use Hypervel\Support\Str;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class RedisFeature extends Feature
{
    use ResolvesEventOrigin;
    use ResolvesSessionKey;

    /**
     * Indicates whether to attempt to detect the session key when running in the console.
     *
     * Tests only. This mutates the feature singleton and should not be used at runtime.
     *
     * @internal this is mainly intended for testing purposes
     */
    public bool $detectSessionKeyOnConsole = false;

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled('redis_commands');
    }

    public function onBoot(): void
    {
        $this->container->make(RedisManager::class)->enableEvents();

        $dispatcher = $this->container->make('events');
        $dispatcher->listen(CommandExecuted::class, [$this, 'handleRedisCommands']);
        $dispatcher->listen(CommandFailed::class, [$this, 'handleFailedRedisCommands']);
    }

    public function handleRedisCommands(CommandExecuted $event): void
    {
        $this->recordCommand($event);
    }

    /**
     * Record a failed Redis command as an error span.
     */
    public function handleFailedRedisCommands(CommandFailed $event): void
    {
        $this->recordCommand($event);
    }

    /**
     * Record a completed Redis command.
     */
    private function recordCommand(CommandExecuted|CommandFailed $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $pool = $this->container->make(PoolFactory::class)->getPool($event->connectionName);
        $redisConfig = $this->container->make(RedisConfig::class);
        $config = $redisConfig->connectionConfig($event->connectionName);

        $keyForDescription = '';
        $firstParameter = $event->parameters[0] ?? null;

        // If the first parameter is a string and does not contain a newline we use it as the description since it's most likely a key.
        // This is not a perfect solution but it's the best we can do without understanding the command that was executed.
        if (is_string($firstParameter) && $firstParameter !== '' && ! Str::contains(
            $firstParameter,
            "\n"
        )) {
            $keyForDescription = $this->replaceSessionKey($firstParameter);
        }

        $redisStatement = rtrim(strtoupper($event->command) . ' ' . $keyForDescription);

        $data = [
            'coroutine.id' => Coroutine::id(),
            'db.system' => 'redis',
            'db.statement' => $redisStatement,
            'db.redis.connection' => $event->connectionName,
            'db.redis.database_index' => (int) ($config['database'] ?? 0),
            'db.redis.pool.name' => $event->connectionName,
            'db.redis.pool.max' => $pool->getOption()->getMaxConnections(),
            'db.redis.pool.max_idle_time' => $pool->getOption()->getMaxIdleTime(),
            'db.redis.pool.idle' => $pool->getConnectionsInChannel(),
            'db.redis.pool.using' => $pool->getCurrentConnections(),
        ];

        if ($event instanceof CommandFailed) {
            $data['db.redis.error'] = $event->exception->getMessage();
        }

        $context = SpanContext::make()
            ->setOp('db.redis')
            ->setOrigin('auto.cache.redis')
            ->setDescription($redisStatement);

        if ($event instanceof CommandFailed) {
            $context->setStatus(SpanStatus::internalError());
        }

        if ($event->time !== null) {
            $context->setStartTimestamp(microtime(true) - $event->time / 1000);
            $context->setEndTimestamp($context->getStartTimestamp() + $event->time / 1000);
            $data['duration'] = $event->time;
        } else {
            $now = microtime(true);
            $context->setStartTimestamp($now);
            $context->setEndTimestamp($now);
        }

        if ($this->shouldSendDefaultPii()) {
            $data['db.redis.parameters'] = $this->replaceSessionKeys($event->parameters);
        }

        if ($this->isTracingFeatureEnabled('redis_origin')) {
            $commandOrigin = $this->resolveEventOrigin();

            if ($commandOrigin !== null) {
                $data = array_merge($data, $commandOrigin);
            }
        }

        $context->setData($data);

        $parentSpan->startChild($context);
    }
}
