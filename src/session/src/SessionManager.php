<?php

declare(strict_types=1);

namespace Hypervel\Session;

use BadMethodCallException;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Session\Contracts\CanManageUserSessions;
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
        $connection = $this->config->get('session.connection');

        if ($connection === null || $connection === '') {
            $connection = 'session';
        }

        return $this->buildSession(new RedisSessionHandler(
            $this->container->make(RedisFactory::class),
            $connection,
            $this->config->string('session.prefix'),
            $this->config->integer('session.lifetime'),
            $this->config->boolean('session.track_user_sessions'),
            $this->container,
        ));
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
     * Determine if the configured driver supports user session management.
     */
    public function supportsUserSessionManagement(): bool
    {
        /** @var Store $store */
        $store = $this->driver();
        $handler = $store->getHandler();

        return $handler instanceof CanManageUserSessions
            && $handler->supportsUserSessionManagement();
    }

    /**
     * Get a user-scoped session repository.
     */
    public function forUser(
        Authenticatable|int|string $user,
        UnitEnum|string|null $guard = null,
    ): UserSessions {
        /** @var Store $store */
        $store = $this->driver();
        $handler = $store->getHandler();

        if (! $handler instanceof CanManageUserSessions
            || ! $handler->supportsUserSessionManagement()) {
            throw new BadMethodCallException(
                'This session driver does not support user session management.'
            );
        }

        if ($guard instanceof UnitEnum) {
            $guard = (string) enum_value($guard);
        }

        /** @var AuthManager $auth */
        $auth = $this->container->make('auth');
        $guard ??= $auth->getDefaultDriver();
        $authProvider = $auth->getUserProviderName($guard);

        if ($authProvider === null) {
            throw new InvalidArgumentException(
                "Auth guard [{$guard}] does not declare a user provider. Set auth.guards.{$guard}.provider."
            );
        }

        $provider = $this->config->get("auth.providers.{$authProvider}");

        if ($user instanceof Authenticatable
            && is_array($provider)
            && ($provider['driver'] ?? null) === 'eloquent'
            && is_string($provider['model'] ?? null)
            && ! $user instanceof $provider['model']) {
            throw new InvalidArgumentException(
                sprintf(
                    'User [%s] does not belong to auth provider [%s].',
                    $user::class,
                    $authProvider,
                )
            );
        }

        $userId = $user instanceof Authenticatable
            ? $user->getAuthIdentifier()
            : $user;

        if (! is_int($userId) && ! is_string($userId)) {
            throw new InvalidArgumentException(
                'The user identifier must be an integer or string.'
            );
        }

        return new UserSessions(
            $authProvider,
            UserSessionIdentity::normalize($userId),
            $handler,
            $store,
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
