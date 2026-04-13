<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Exception;
use Hypervel\Contracts\Session\Session;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisConfig;
use Hypervel\Sentry\Features\Concerns\ResolvesEventOrigin;
use Hypervel\Support\Str;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

class RedisFeature extends Feature
{
    use ResolvesEventOrigin;

    /**
     * Indicates whether to attempt to detect the session key when running in the console.
     *
     * Instance property (not static) because this feature is a container singleton —
     * a static would persist across worker lifetime and leak between tests.
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
        $config = $this->container->make('config');
        $redisConfig = $this->container->make(RedisConfig::class);

        foreach ($redisConfig->connectionNames() as $connection) {
            $config->set("database.redis.{$connection}.event.enable", true);
        }

        $dispatcher = $this->container->make('events');
        $dispatcher->listen(CommandExecuted::class, [$this, 'handleRedisCommands']);
        $dispatcher->listen(CommandFailed::class, [$this, 'handleFailedRedisCommands']);
    }

    public function handleRedisCommands(CommandExecuted $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $pool = $this->container->make(PoolFactory::class)->getPool($event->connectionName);
        $redisConfig = $this->container->make(RedisConfig::class);
        $config = $redisConfig->connectionConfig($event->connectionName);

        $keyForDescription = '';

        // If the first parameter is a string and does not contain a newline we use it as the description since it's most likely a key
        // This is not a perfect solution but it's the best we can do without understanding the command that was executed
        if (! empty($event->parameters[0]) && is_string($event->parameters[0]) && ! Str::contains(
            $event->parameters[0],
            "\n"
        )) {
            $keyForDescription = $this->replaceSessionKey($event->parameters[0]);
        }

        $redisStatement = rtrim(strtoupper($event->command) . ' ' . $keyForDescription);

        $data = [
            'coroutine.id' => Coroutine::id(),
            'db.system' => 'redis',
            'db.statement' => $redisStatement,
            'db.redis.connection' => $event->connectionName,
            'db.redis.database_index' => $config['db'] ?? 0,
            'db.redis.parameters' => $event->parameters,
            'db.redis.pool.name' => $event->connectionName,
            'db.redis.pool.max' => $pool->getOption()->getMaxConnections(),
            'db.redis.pool.max_idle_time' => $pool->getOption()->getMaxIdleTime(),
            'db.redis.pool.idle' => $pool->getConnectionsInChannel(),
            'db.redis.pool.using' => $pool->getCurrentConnections(),
            'duration' => $event->time,
        ];

        $context = SpanContext::make()
            ->setOp('db.redis')
            ->setOrigin('auto.cache.redis')
            ->setDescription($redisStatement);
        $context->setStartTimestamp(microtime(true) - $event->time / 1000);
        $context->setEndTimestamp($context->getStartTimestamp() + $event->time / 1000);

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

    /**
     * Record a failed Redis command as an error span.
     */
    public function handleFailedRedisCommands(CommandFailed $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $pool = $this->container->make(PoolFactory::class)->getPool($event->connectionName);
        $redisConfig = $this->container->make(RedisConfig::class);
        $config = $redisConfig->connectionConfig($event->connectionName);

        $keyForDescription = '';

        if (! empty($event->parameters[0]) && is_string($event->parameters[0]) && ! Str::contains(
            $event->parameters[0],
            "\n"
        )) {
            $keyForDescription = $this->replaceSessionKey($event->parameters[0]);
        }

        $redisStatement = rtrim(strtoupper($event->command) . ' ' . $keyForDescription);

        $data = [
            'coroutine.id' => Coroutine::id(),
            'db.system' => 'redis',
            'db.statement' => $redisStatement,
            'db.redis.connection' => $event->connectionName,
            'db.redis.database_index' => $config['db'] ?? 0,
            'db.redis.parameters' => $event->parameters,
            'db.redis.pool.name' => $event->connectionName,
            'db.redis.pool.max' => $pool->getOption()->getMaxConnections(),
            'db.redis.pool.max_idle_time' => $pool->getOption()->getMaxIdleTime(),
            'db.redis.pool.idle' => $pool->getConnectionsInChannel(),
            'db.redis.pool.using' => $pool->getCurrentConnections(),
            'db.redis.error' => $event->exception->getMessage(),
        ];

        $context = SpanContext::make()
            ->setOp('db.redis')
            ->setOrigin('auto.cache.redis')
            ->setDescription($redisStatement)
            ->setStatus(\Sentry\Tracing\SpanStatus::internalError());

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

    /**
     * Retrieve the current session key if available.
     */
    private function getSessionKey(): ?string
    {
        try {
            // Skip session resolution in the console to avoid unnecessary database connections
            // (e.g. when using a database session driver during artisan commands)
            if (! $this->detectSessionKeyOnConsole && app()->runningInConsole()) {
                return null;
            }

            /** @var Session $sessionStore */
            $sessionStore = $this->container->make('session.store');

            // It is safe for us to get the session ID here without checking if the session is started
            // because getting the session ID does not start the session. In addition we need the ID before
            // the session is started because the cache will retrieve the session ID from the cache before the session
            // is considered started. So if we wait for the session to be started, we will not be able to replace the
            // session key in the cache operation that is being executed to retrieve the session data from the cache.
            return $sessionStore->getId();
        } catch (Exception) {
            // We can assume the session store is not available here so there is no session key to retrieve
            // We capture a generic exception to avoid breaking the application because some code paths can
            // result in an exception other than the expected `Hypervel\Contracts\Container\BindingResolutionException`
            return null;
        }
    }

    /**
     * Replace session keys in an array of keys with placeholders.
     *
     * @param string[] $values
     */
    private function replaceSessionKeys(array $values): array
    {
        $sessionKey = $this->getSessionKey();

        return array_map(static function ($value) use ($sessionKey) {
            // @phpstan-ignore function.alreadyNarrowedType (defensive: event data may contain non-strings)
            return is_string($value) && $value === $sessionKey ? '{sessionKey}' : $value;
        }, $values);
    }

    /**
     * Replace a session key with a placeholder.
     */
    private function replaceSessionKey(?string $value): string
    {
        if (! is_string($value)) {
            return '{empty key}';
        }

        return $value === $this->getSessionKey() ? '{sessionKey}' : $value;
    }
}
