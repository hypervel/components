<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\HttpServer\Request;
use Hyperf\Support\Filesystem\Filesystem;
use Hypervel\Cache\Contracts\Factory as CacheContract;
use Hypervel\Cookie\Contracts\Cookie as CookieContract;
use Hypervel\Encryption\Contracts\Encrypter;
use Hypervel\Session\Contracts\Factory;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Support\Manager;
use SessionHandlerInterface;

/**
 * @mixin \Hypervel\Session\Store
 */
class SessionManager extends Manager implements Factory
{
    /**
     * Get a session store instance by name.
     */
    public function store(?string $name = null): SessionContract
    {
        return $this->driver($name);
    }

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
        return $this->buildSession(new NullSessionHandler());
    }

    /**
     * Create an instance of the "array" session driver.
     */
    protected function createArrayDriver(): Store
    {
        return $this->buildSession(new ArraySessionHandler(
            $this->config->get('session.lifetime')
        ));
    }

    /**
     * Create an instance of the "cookie" session driver.
     */
    protected function createCookieDriver(): Store
    {
        return $this->buildSession(new CookieSessionHandler(
            $this->container->get(CookieContract::class),
            $this->container->get(Request::class),
            $this->config->get('session.lifetime'),
            $this->config->get('session.expire_on_close')
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
        $lifetime = $this->config->get('session.lifetime');

        return $this->buildSession(new FileSessionHandler(
            $this->container->get(Filesystem::class),
            $this->config->get('session.files'),
            $lifetime
        ));
    }

    /**
     * Create an instance of the database session driver.
     */
    protected function createDatabaseDriver(): Store
    {
        $table = $this->config->get('session.table');

        $lifetime = $this->config->get('session.lifetime');

        return $this->buildSession(new DatabaseSessionHandler(
            $this->container->get(ConnectionResolverInterface::class),
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

        return $this->buildSession($handler);
    }

    /**
     * Create the cache based session handler instance.
     */
    protected function createCacheHandler(string $driver): CacheBasedSessionHandler
    {
        return new CacheBasedSessionHandler(
            $this->container->get(CacheContract::class),
            $this->config->get('session.store') ?: $driver,
            $this->config->get('session.lifetime')
        );
    }

    /**
     * Build the session instance.
     */
    protected function buildSession(SessionHandlerInterface $handler): Store
    {
        return $this->config->get('session.encrypt')
            ? $this->buildEncryptedSession($handler)
            : new Store(
                $this->config->get('session.cookie'),
                $handler,
                $this->config->get('session.serialization', 'php')
            );
    }

    /**
     * Build the encrypted session instance.
     */
    protected function buildEncryptedSession(SessionHandlerInterface $handler): EncryptedStore
    {
        return new EncryptedStore(
            $this->config->get('session.cookie'),
            $handler,
            $this->container->get(Encrypter::class),
            $this->config->get('session.serialization', 'php'),
        );
    }

    /**
     * Determine if requests for the same session should wait for each to finish before executing.
     */
    public function shouldBlock(): bool
    {
        return $this->config->get('session.block', false);
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
        return $this->config->get('session.block_lock_seconds', 10);
    }

    /**
     * Get the maximum number of seconds to wait while attempting to acquire a route block session lock.
     */
    public function defaultRouteBlockWaitSeconds(): int
    {
        return $this->config->get('session.block_wait_seconds', 10);
    }

    /**
     * Get the session configuration.
     */
    public function getSessionConfig(): array
    {
        return $this->config->get('session');
    }

    /**
     * Get the default session driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('session.driver');
    }

    /**
     * Set the default session driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->config->set('session.driver', $name);
    }
}
