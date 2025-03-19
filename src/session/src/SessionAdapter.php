<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hyperf\Contract\SessionInterface;
use Hypervel\Session\Contracts\Session as SessionContract;
use Psr\Container\ContainerInterface;
use RuntimeException;

class SessionAdapter implements SessionInterface
{
    public function __invoke(ContainerInterface $container): SessionInterface
    {
        return new static(
            $container->get(SessionContract::class)
        );
    }

    public function __construct(
        protected SessionContract $session,
    ) {
    }

    /**
     * Starts the session storage.
     *
     * @return bool True if session started
     * @throws RuntimeException if session fails to start
     */
    public function start(): bool
    {
        return $this->session->start();
    }

    /**
     * Returns the session ID.
     *
     * @return string The session ID
     */
    public function getId(): string
    {
        return $this->session->getId();
    }

    /**
     * Sets the session ID.
     */
    public function setId(string $id): void
    {
        $this->session->setId($id);
    }

    /**
     * Returns the session name.
     */
    public function getName(): string
    {
        return $this->session->getName();
    }

    /**
     * Sets the session name.
     */
    public function setName(string $name): void
    {
        $this->session->setName($name);
    }

    /**
     * Invalidates the current session.
     *
     * Clears all session attributes and flashes and regenerates the
     * session and deletes the old session from persistence.
     *
     * @param int $lifetime Sets the cookie lifetime for the session cookie. A null value
     *                      will leave the system settings unchanged, 0 sets the cookie
     *                      to expire with browser session. Time is in seconds, and is
     *                      not a Unix timestamp.
     *
     * @return bool True if session invalidated, false if error
     */
    public function invalidate(?int $lifetime = null): bool
    {
        return $this->session->invalidate();
    }

    /**
     * Migrates the current session to a new session id while maintaining all
     * session attributes.
     *
     * @param bool $destroy Whether to delete the old session or leave it to garbage collection
     * @param int $lifetime Sets the cookie lifetime for the session cookie. A null value
     *                      will leave the system settings unchanged, 0 sets the cookie
     *                      to expire with browser session. Time is in seconds, and is
     *                      not a Unix timestamp.
     *
     * @return bool True if session migrated, false if error
     */
    public function migrate(bool $destroy = false, ?int $lifetime = null): bool
    {
        return $this->session->migrate($destroy);
    }

    /**
     * Force the session to be saved and closed.
     *
     * This method is generally not required for real sessions as
     * the session will be automatically saved at the end of
     * code execution.
     */
    public function save(): void
    {
        $this->session->save();
    }

    /**
     * Checks if an attribute is defined.
     *
     * @param string $name The attribute name
     *
     * @return bool true if the attribute is defined, false otherwise
     */
    public function has(string $name): bool
    {
        return $this->session->has($name);
    }

    /**
     * Returns an attribute.
     *
     * @param string $name The attribute name
     * @param mixed $default The default value if not found
     */
    public function get(string $name, $default = null): mixed
    {
        return $this->session->get($name, $default);
    }

    /**
     * Sets an attribute.
     * @param mixed $value
     */
    public function set(string $name, $value): void
    {
        $this->session->put($name, $value);
    }

    /**
     * Put a key / value pair or array of key / value pairs in the session.
     *
     * @param array|string $key
     * @param null|mixed $value
     */
    public function put($key, $value = null): void
    {
        $this->session->put($key, $value);
    }

    /**
     * Returns attributes.
     */
    public function all(): array
    {
        return $this->session->all();
    }

    /**
     * Sets attributes.
     */
    public function replace(array $attributes): void
    {
        $this->session->put($attributes);
    }

    /**
     * Removes an attribute, returning its value.
     *
     * @return mixed The removed value or null when it does not exist
     */
    public function remove(string $name): mixed
    {
        return $this->session->remove($name);
    }

    /**
     * Remove one or many items from the session.
     *
     * @param array|string $keys
     */
    public function forget($keys): void
    {
        $this->session->forget($keys);
    }

    /**
     * Clears all attributes.
     */
    public function clear(): void
    {
        $this->session->flush();
    }

    /**
     * Checks if the session was started.
     */
    public function isStarted(): bool
    {
        return $this->session->isStarted();
    }

    /**
     * Get the previous URL from the session.
     */
    public function previousUrl(): ?string
    {
        return $this->session->previousUrl();
    }

    /**
     * Set the "previous" URL in the session.
     */
    public function setPreviousUrl(string $url): void
    {
        $this->session->setPreviousUrl($url);
    }
}
