<?php

declare(strict_types=1);

namespace Hypervel\Auth\Guards;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Support\Traits\Macroable;
use Throwable;

class RequestGuard implements Guard
{
    use GuardHelpers;
    use Macroable;

    /**
     * The request instance.
     */
    protected RequestInterface $request;

    /**
     * The callback that should be used to authenticate users.
     */
    protected $callback;

    public function __construct(
        protected UserProvider $provider,
        callable $callback
    ) {
        $this->callback = $callback;
        $this->request = ApplicationContext::getContainer()
            ->get(RequestInterface::class);
    }

    public function user(): ?Authenticatable
    {
        // cache user in context
        if (Context::has($contextKey = $this->getContextKey())) {
            return Context::get($contextKey);
        }

        $user = null;
        try {
            $user = call_user_func($this->callback, $this->getProvider());
            Context::set($contextKey, $user ?? null);
        } catch (Throwable $exception) {
            Context::set($contextKey, null);
        }

        return $user;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return ! is_null($this->user());
    }

    public function setUser(Authenticatable $user): void
    {
        Context::set($this->getContextKey(), $user);
    }

    protected function getContextKey(): string
    {
        return '__auth.guards.request';
    }
}
