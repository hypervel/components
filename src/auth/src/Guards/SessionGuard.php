<?php

declare(strict_types=1);

namespace Hypervel\Auth\Guards;

use Hyperf\Context\Context;
use Hyperf\Macroable\Macroable;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\StatefulGuard;
use Hypervel\Auth\Contracts\UserProvider;
use Hypervel\Session\Contracts\Session as SessionContract;
use Throwable;

class SessionGuard implements StatefulGuard
{
    use GuardHelpers;
    use Macroable;

    public function __construct(
        protected string $name,
        protected UserProvider $provider,
        protected SessionContract $session
    ) {
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     */
    public function attempt(array $credentials = [], bool $login = true): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        // If an implementation of UserInterface was returned, we'll ask the provider
        // to validate the user against the given credentials, and if they are in
        // fact valid we'll log the users into the application and return true.
        $result = $this->hasValidCredentials($user, $credentials);
        if ($result && $login) {
            $this->login($user);
        }

        return $result;
    }

    /**
     * Log a user into the application without sessions or cookies.
     */
    public function once(array $credentials = []): bool
    {
        if ($this->attempt($credentials)) {
            $this->setUser($this->user());

            return true;
        }

        return false;
    }

    /**
     * Log a user into the application.
     */
    public function login(Authenticatable $user): void
    {
        $this->updateSession($user->getAuthIdentifier());

        $this->setUser($user);
    }

    /**
     * Update the session with the given ID.
     */
    protected function updateSession(int|string $id): void
    {
        $this->session->put($this->sessionKey(), $id);

        $this->session->migrate(true);
    }

    /**
     * Log the given user ID into the application without sessions or cookies.
     */
    public function onceUsingId(mixed $id): Authenticatable|bool
    {
        if (! is_null($user = $this->provider->retrieveById($id))) {
            $this->setUser($user);

            return $user;
        }

        return false;
    }

    public function getContextKey(): string
    {
        return "auth.guards.{$this->name}.result:" . $this->session->getId();
    }

    protected function getUnstartedContextKey(): string
    {
        return "auth.guards.{$this->name}.unstarted";
    }

    public function user(): ?Authenticatable
    {
        // cache user in context
        if (Context::has($contextKey = $this->getContextKey())) {
            return Context::get($contextKey);
        }

        // cache user in context if session is not started but user is set
        if (Context::has($unstartedContextKey = $this->getUnstartedContextKey())) {
            return Context::get($unstartedContextKey);
        }

        $user = null;
        try {
            if ($id = $this->session->get($this->sessionKey())) {
                $user = $this->provider->retrieveById($id);
                Context::set($contextKey, $user ?? null);
            }
        } catch (Throwable $exception) {
            Context::set($contextKey, null);
        }

        return $user;
    }

    public function logout(): void
    {
        Context::destroy($this->getUnstartedContextKey());
        Context::destroy($this->getContextKey());
        $this->session->remove($this->sessionKey());
    }

    public function setUser(Authenticatable $user): void
    {
        if (! $this->session->isStarted()) {
            Context::set($this->getUnstartedContextKey(), $user);
            return;
        }

        Context::set($this->getContextKey(), $user);
    }

    protected function sessionKey(): string
    {
        return 'auth.guards.session.' . $this->name;
    }
}
