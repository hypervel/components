<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Session\Concerns\ValidatesUserSessionArguments;
use Hypervel\Session\Contracts\CanManageUserSessions;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\InteractsWithTime;
use SessionHandlerInterface;

class DatabaseSessionHandler implements CanManageUserSessions, ExistenceAwareInterface, SessionHandlerInterface
{
    use InteractsWithTime;
    use ValidatesUserSessionArguments;

    /**
     * Context key prefix for whether the session record exists in the database.
     *
     * Suffixed with the handler's object ID so multiple handler instances
     * within the same coroutine maintain independent existence state.
     */
    protected const string DATABASE_EXISTS_CONTEXT_KEY_PREFIX = '__session.database.exists.';

    /**
     * Context key prefix for whether the session record is expired.
     *
     * Suffixed with the handler's object ID so multiple handler instances
     * within the same coroutine maintain independent expiration state.
     */
    protected const string DATABASE_EXPIRED_CONTEXT_KEY_PREFIX = '__session.database.expired.';

    /**
     * Create a new database session handler instance.
     *
     * @param ConnectionResolverInterface $resolver the database connection resolver instance
     * @param null|string $connection the database connection that should be used
     * @param string $table the name of the session table
     * @param int $minutes the number of minutes the session should be valid
     */
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        protected ?string $connection,
        protected string $table,
        protected int $minutes,
        protected ?Container $container = null
    ) {
        $this->forgetRecordState();
    }

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): false|string
    {
        $session = (object) $this->getQuery()->find($sessionId);

        if ($this->expired($session)) {
            $this->setExists(true);
            $this->setExpired(true);

            return '';
        }

        if (isset($session->payload)) {
            $this->setExists(true);
            $this->setExpired(false);

            return base64_decode($session->payload);
        }

        $this->setExists(false);

        return '';
    }

    /**
     * Determine if the session is expired.
     */
    protected function expired(object $session): bool
    {
        return isset($session->last_activity)
            && $session->last_activity <= $this->activeAfter();
    }

    public function write(string $sessionId, string $data): bool
    {
        $exists = $this->existenceState();

        if ($exists === null) {
            $this->read($sessionId);
            $exists = $this->getExists();
        }

        $payload = $this->getDefaultPayload($sessionId, $data);

        if ($exists) {
            $this->performUpdate($sessionId, $payload);
        } else {
            $this->performInsert($sessionId, $payload);
        }

        $this->setExists(true);
        $this->setExpired(false);

        return true;
    }

    /**
     * Perform an insert operation on the session ID.
     *
     * @param array<string, mixed> $payload
     */
    protected function performInsert(string $sessionId, array $payload): bool
    {
        $insertPayload = $payload;
        $insertPayload['id'] = $sessionId;

        try {
            return $this->getQuery()->insert($insertPayload);
        } catch (UniqueConstraintViolationException) {
            $this->performUpdate($sessionId, $payload);
        }

        return false;
    }

    /**
     * Perform an update operation on the session ID.
     *
     * @param array<string, mixed> $payload
     */
    protected function performUpdate(string $sessionId, array $payload): int
    {
        return $this->getQuery()->where('id', $sessionId)->update($payload);
    }

    /**
     * Get the default payload for the session.
     */
    protected function getDefaultPayload(string $sessionId, string $data): array
    {
        $payload = [
            'payload' => base64_encode($data),
            'last_activity' => $this->currentTime(),
        ];

        // Provider-qualified ownership replaces Laravel's scalar userId() and
        // addUserInformation() hooks, so both fields are written or omitted together.
        $identity = UserSessionIdentity::resolve($this->container, $sessionId);

        if ($identity->isResolved()) {
            $payload['auth_provider'] = $identity->authProvider;
            $payload['user_id'] = $identity->userId;
        } elseif ($identity->isUnowned() || ! $this->getExists() || $this->getExpired()) {
            $payload['auth_provider'] = null;
            $payload['user_id'] = null;
        }

        if ($this->container !== null) {
            $this->addRequestInformation($payload);
        }

        return $payload;
    }

    /**
     * Add the request information to the session payload.
     */
    protected function addRequestInformation(array &$payload): static
    {
        if (RequestContext::has()) {
            $payload = array_merge($payload, [
                'ip_address' => $this->ipAddress(),
                'user_agent' => $this->userAgent(),
            ]);
        }

        return $this;
    }

    /**
     * Get the IP address for the current request.
     */
    protected function ipAddress(): ?string
    {
        return $this->container->make('request')->ip();
    }

    /**
     * Get the user agent for the current request.
     */
    protected function userAgent(): string
    {
        return mb_substr(mb_convert_encoding((string) $this->container->make('request')->header('User-Agent'), 'UTF-8'), 0, 500);
    }

    public function destroy(string $sessionId): bool
    {
        $this->getQuery()->where('id', $sessionId)->delete();

        return true;
    }

    public function gc(int $lifetime): int
    {
        return $this->getQuery()->where('last_activity', '<=', $this->currentTime() - $lifetime)->delete();
    }

    /**
     * Determine if the handler supports user session management.
     */
    public function supportsUserSessionManagement(): bool
    {
        return true;
    }

    /**
     * Get the active sessions for the given user.
     *
     * @return Collection<int, UserSession>
     */
    public function userSessions(string $authProvider, int|string $userId): Collection
    {
        $this->validateAuthProvider($authProvider);
        $normalizedUserId = UserSessionIdentity::normalize($userId);
        $activeAfter = $this->activeAfter();

        return $this->getQuery()
            ->select(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->where('auth_provider', $authProvider)
            ->where('user_id', $normalizedUserId)
            ->where('last_activity', '>', $activeAfter)
            ->orderByDesc('last_activity')
            ->orderBy('id')
            ->get()
            ->map(function (object $session): UserSession {
                $lastActivity = CarbonImmutable::createFromTimestampUTC((int) $session->last_activity);

                return new UserSession(
                    (string) $session->id,
                    $session->ip_address === null ? null : (string) $session->ip_address,
                    $session->user_agent === null ? null : (string) $session->user_agent,
                    $lastActivity,
                    $lastActivity->addMinutes($this->minutes),
                );
            });
    }

    /**
     * Destroy an active session belonging to the given user.
     */
    public function destroyUserSession(
        string $authProvider,
        int|string $userId,
        string $sessionId,
    ): bool {
        $this->validateAuthProvider($authProvider);
        $normalizedUserId = UserSessionIdentity::normalize($userId);
        SessionId::validate($sessionId);

        return $this->getQuery()
            ->where('auth_provider', $authProvider)
            ->where('user_id', $normalizedUserId)
            ->where('id', $sessionId)
            ->where('last_activity', '>', $this->activeAfter())
            ->delete() > 0;
    }

    /**
     * Destroy the active sessions belonging to the given user.
     *
     * @param list<string> $except
     */
    public function destroyUserSessions(
        string $authProvider,
        int|string $userId,
        array $except = [],
    ): int {
        $this->validateAuthProvider($authProvider);
        $normalizedUserId = UserSessionIdentity::normalize($userId);
        $except = $this->normalizeSessionIds($except);
        $query = $this->getQuery()
            ->where('auth_provider', $authProvider)
            ->where('user_id', $normalizedUserId)
            ->where('last_activity', '>', $this->activeAfter());

        if ($except !== []) {
            $query->whereNotIn('id', $except);
        }

        return $query->delete();
    }

    /**
     * Get the exclusive lower boundary for active sessions.
     */
    protected function activeAfter(): int
    {
        return $this->currentTime() - ($this->minutes * 60);
    }

    /**
     * Get a fresh query builder instance for the table.
     */
    protected function getQuery(): Builder
    {
        return $this->connection()->table($this->table)->useWritePdo();
    }

    /**
     * Get the underlying database connection.
     */
    public function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    /**
     * Set the application instance used by the handler.
     *
     * Boot or tests only. Mutating the container on a shared handler during
     * request handling can expose the wrong request or authentication state
     * to concurrent coroutines.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Set the existence state for the session.
     */
    public function setExists(bool $value): static
    {
        CoroutineContext::set(self::DATABASE_EXISTS_CONTEXT_KEY_PREFIX . spl_object_id($this), $value);

        if (! $value) {
            $this->setExpired(false);
        }

        return $this;
    }

    /**
     * Get the existence state for the session.
     */
    public function getExists(): bool
    {
        return $this->existenceState() ?? false;
    }

    /**
     * Get the raw existence state for the current session record.
     */
    protected function existenceState(): ?bool
    {
        return CoroutineContext::get(self::DATABASE_EXISTS_CONTEXT_KEY_PREFIX . spl_object_id($this));
    }

    /**
     * Set whether the current session record is expired.
     */
    protected function setExpired(bool $value): static
    {
        CoroutineContext::set(self::DATABASE_EXPIRED_CONTEXT_KEY_PREFIX . spl_object_id($this), $value);

        return $this;
    }

    /**
     * Determine if the current session record is expired.
     */
    protected function getExpired(): bool
    {
        return CoroutineContext::get(self::DATABASE_EXPIRED_CONTEXT_KEY_PREFIX . spl_object_id($this), false);
    }

    /**
     * Forget the current session record state.
     */
    protected function forgetRecordState(): void
    {
        $objectId = spl_object_id($this);

        CoroutineContext::forget(self::DATABASE_EXISTS_CONTEXT_KEY_PREFIX . $objectId);
        CoroutineContext::forget(self::DATABASE_EXPIRED_CONTEXT_KEY_PREFIX . $objectId);
    }

    /**
     * Reset this handler's existence state when it is cloned.
     *
     * PHP reuses freed object IDs, so a clone can land on a released handler's
     * ID and must not inherit its existence state.
     */
    public function __clone(): void
    {
        $this->forgetRecordState();
    }
}
