<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Carbon\Carbon;
use Hyperf\Collection\Arr;
use Hyperf\Context\Context;
use Hyperf\Context\RequestContext;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Exception\QueryException;
use Hyperf\Database\Query\Builder;
use Hyperf\HttpServer\Request;
use Hypervel\Auth\Contracts\Guard;
use Hypervel\Support\Traits\InteractsWithTime;
use Psr\Container\ContainerInterface;
use SessionHandlerInterface;

use function Hyperf\Tappable\tap;

class DatabaseSessionHandler implements ExistenceAwareInterface, SessionHandlerInterface
{
    use InteractsWithTime;

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
        protected ?ContainerInterface $container = null
    ) {
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

            return '';
        }

        if (isset($session->payload)) {
            $this->setExists(true);

            return base64_decode($session->payload);
        }

        return '';
    }

    /**
     * Determine if the session is expired.
     */
    protected function expired(object $session): bool
    {
        return isset($session->last_activity)
            && $session->last_activity < Carbon::now()->subMinutes($this->minutes)->getTimestamp();
    }

    public function write(string $sessionId, string $data): bool
    {
        $payload = $this->getDefaultPayload($data);

        if (! $exists = $this->getExists()) {
            $this->read($sessionId);
        }

        if ($exists) {
            $this->performUpdate($sessionId, $payload);
        } else {
            $this->performInsert($sessionId, $payload);
        }

        $this->setExists(true);

        return true;
    }

    /**
     * Perform an insert operation on the session ID.
     *
     * @param array<string, mixed> $payload
     */
    protected function performInsert(string $sessionId, array $payload): ?bool
    {
        try {
            return $this->getQuery()->insert(Arr::set($payload, 'id', $sessionId));
        } catch (QueryException) {
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
    protected function getDefaultPayload(string $data): array
    {
        $payload = [
            'payload' => base64_encode($data),
            'last_activity' => $this->currentTime(),
        ];

        if (! $this->container) {
            return $payload;
        }

        return tap($payload, function (&$payload) {
            $this->addUserInformation($payload)
                ->addRequestInformation($payload);
        });
    }

    /**
     * Add the user information to the session payload.
     */
    protected function addUserInformation(array &$payload): static
    {
        if ($this->container->has(Guard::class)) {
            $payload['user_id'] = $this->userId();
        }

        return $this;
    }

    /**
     * Get the currently authenticated user's ID.
     */
    protected function userId(): mixed
    {
        return $this->container->get(Guard::class)->id();
    }

    /**
     * Add the request information to the session payload.
     */
    protected function addRequestInformation(array &$payload): static
    {
        if ($this->container->has(Request::class)) {
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
        if (! RequestContext::has()) {
            return '127.0.0.1';
        }

        $request = $this->container->get(Request::class);

        return $request->getHeaderLine('x-real-ip')
            ?: $request->server('remote_addr');
    }

    /**
     * Get the user agent for the current request.
     */
    protected function userAgent(): string
    {
        return substr((string) $this->container->get(Request::class)->header('User-Agent'), 0, 500);
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
     * Set the connection name to be used.
     */
    public function setConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Set the application instance used by the handler.
     */
    public function setContainer(ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Set the existence state for the session.
     */
    public function setExists(bool $value): static
    {
        Context::set('_session.database.exists', $value);

        return $this;
    }

    /**
     * Get the existence state for the session.
     */
    public function getExists(): bool
    {
        return Context::get('_session.database.exists', false);
    }
}
