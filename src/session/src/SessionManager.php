<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Support\Manager;
use InvalidArgumentException;
use SessionHandlerInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Session\Store
 */
class SessionManager extends Manager
{
    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(string $driver): Store
    {
        return $this->buildSession(parent::callCustomCreator($driver));
    }

    /**
     * Create an instance of the "null" session driver.
     */
    protected function createNullDriver(): Store
    {
        return $this->buildSession(new NullSessionHandler);
    }

    /**
     * Create an instance of the "array" session driver.
     */
    protected function createArrayDriver(): Store
    {
        return $this->buildSession(new ArraySessionHandler(
            $this->config->integer('session.lifetime')
        ));
    }

    /**
     * Create an instance of the "cookie" session driver.
     */
    protected function createCookieDriver(): Store
    {
        return $this->buildSession(new CookieSessionHandler(
            $this->container->make('cookie'),
            $this->config->integer('session.lifetime'),
            $this->config->boolean('session.expire_on_close')
        ));
    }

    /**
     * Create an instance of the file session driver.
     */
    protected function createFileDriver(): Store
    {
        return $this->createNativeDriver();
    }

    /**
     * Create an instance of the file session driver.
     */
    protected function createNativeDriver(): Store
    {
        $lifetime = $this->config->integer('session.lifetime');

        return $this->buildSession(new FileSessionHandler(
            $this->container->make('files'),
            $this->config->string('session.files'),
            $lifetime
        ));
    }

    /**
     * Create an instance of the database session driver.
     */
    protected function createDatabaseDriver(): Store
    {
        $table = $this->config->string('session.table');

        $lifetime = $this->config->integer('session.lifetime');

        return $this->buildSession(new DatabaseSessionHandler(
            $this->container->make('db'),
            $this->config->get('session.connection'),
            $table,
            $lifetime,
            $this->container
        ));
    }

    /**
     * Create an instance of the Redis session driver.
     */
    protected function createRedisDriver(): Store
    {
        $handler = $this->createCacheHandler('redis');
        $store = $handler->getCache()->getStore();

        if (! $store instanceof RedisStore) {
            throw new InvalidArgumentException(
                'The [session.driver] value [redis] requires [session.store] to reference a Redis cache store.'
            );
        }

        $store->setConnection(
            $this->config->get('session.connection') ?? 'session'
        );

        $prefix = $this->config->get('session.prefix');

        if ($prefix !== null && $prefix !== '') {
            $store->setPrefix($prefix);
        }

        return $this->buildSession($handler);
    }

    // Laravel's apc/memcached/dynamodb drivers and their shared createCacheBased()
    // wrapper are intentionally omitted; Hypervel has no matching cache stores.
    // Register cache-backed handlers with Session::extend().

    /**
     * Create the cache based session handler instance.
     */
    protected function createCacheHandler(string $driver): CacheBasedSessionHandler
    {
        $store = $this->config->get('session.store');
        $store = $store === null || $store === '' ? $driver : $store;

        return new CacheBasedSessionHandler(
            clone $this->container->make('cache')->store($store),
            $this->config->integer('session.lifetime')
        );
    }

    /**
     * Build the session instance.
     */
    protected function buildSession(SessionHandlerInterface $handler): Store
    {
        return $this->config->boolean('session.encrypt')
            ? $this->buildEncryptedSession($handler)
            : new Store(
                $this->config->string('session.cookie'),
                $handler,
                null,
                $this->config->string('session.serialization')
            );
    }

    /**
     * Build the encrypted session instance.
     */
    protected function buildEncryptedSession(SessionHandlerInterface $handler): EncryptedStore
    {
        return new EncryptedStore(
            $this->config->string('session.cookie'),
            $handler,
            $this->container->make(Encrypter::class),
            null,
            $this->config->string('session.serialization'),
        );
    }

    /**
     * Determine if requests for the same session should wait for each to finish before executing.
     */
    public function shouldBlock(): bool
    {
        return $this->config->boolean('session.block');
    }

    /**
     * Get the name of the cache store / driver that should be used to acquire session locks.
     */
    public function blockDriver(): ?string
    {
        return $this->config->get('session.block_store');
    }

    /**
     * Get the maximum number of seconds the session lock should be held for.
     */
    public function defaultRouteBlockLockSeconds(): int
    {
        return $this->config->integer('session.block_lock_seconds');
    }

    /**
     * Get the maximum number of seconds to wait while attempting to acquire a route block session lock.
     */
    public function defaultRouteBlockWaitSeconds(): int
    {
        return $this->config->integer('session.block_wait_seconds');
    }

    /**
     * Get the session configuration.
     */
    public function getSessionConfig(): array
    {
        return $this->config->array('session');
    }

    /**
     * Get the default session driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->string('session.driver');
    }

    /**
     * Set the default session driver name.
     *
     * Boot-only. Mutates process-global config; per-request use races across coroutines.
     */
    public function setDefaultDriver(UnitEnum|string $name): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $this->config->set('session.driver', $name);
    }
}
