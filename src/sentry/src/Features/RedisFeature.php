<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Exception;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Event\CommandExecuted;
use Hyperf\Redis\Pool\PoolFactory;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Sentry\Traits\ResolvesEventOrigin;
use Hypervel\Contracts\Session\Session;
use Hypervel\Support\Str;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

class RedisFeature extends Feature
{
    use ResolvesEventOrigin;

    public function isApplicable(): bool
    {
        return $this->switcher->isTracingEnable('redis_commands');
    }

    public function onBoot(): void
    {
        $config = $this->container->get(ConfigInterface::class);
        if ($config->has('database.connections.redis.event')) {
            $config->set('database.connections.redis.event', true);
        }
        $dispatcher = $this->container->get(Dispatcher::class);
        $dispatcher->listen(CommandExecuted::class, [$this, 'handleRedisCommands']);
    }

    public function handleRedisCommands(CommandExecuted $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $pool = $this->container->get(PoolFactory::class)->getPool($event->connectionName);
        $config = $this->container->get(ConfigInterface::class)->get('redis.' . $event->connectionName, []);

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
            'duration' => $event->time * 1000,
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

        if ($this->switcher->isTracingEnable('redis_origin')) {
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
            $sessionStore = $this->container->get(Session::class);

            // It is safe for us to get the session ID here without checking if the session is started
            // because getting the session ID does not start the session. In addition we need the ID before
            // the session is started because the cache will retrieve the session ID from the cache before the session
            // is considered started. So if we wait for the session to be started, we will not be able to replace the
            // session key in the cache operation that is being executed to retrieve the session data from the cache.
            return $sessionStore->getId();
        } catch (Exception $e) {
            // We can assume the session store is not available here so there is no session key to retrieve
            // We capture a generic exception to avoid breaking the application because some code paths can
            // result in an exception other than the expected `Illuminate\Contracts\Container\BindingResolutionException`
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
