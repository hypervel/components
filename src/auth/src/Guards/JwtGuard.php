<?php

declare(strict_types=1);

namespace Hypervel\Auth\Guards;

use Carbon\Carbon;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use Throwable;

class JwtGuard implements Guard
{
    use GuardHelpers;
    use Macroable;

    public function __construct(
        protected string $name,
        protected UserProvider $provider,
        protected ManagerContract $jwtManager,
        protected RequestInterface $request,
        protected int $ttl = 120
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

    public function parseToken(): ?string
    {
        // prevent nullable request
        if (! RequestContext::has()) {
            return null;
        }

        $header = $this->request->header('Authorization', '');
        if ($header && Str::startsWith($header, 'Bearer ')) {
            return Str::substr($header, 7);
        }

        if ($this->request->has('token')) {
            return $this->request->input('token');
        }

        return null;
    }

    public function login(Authenticatable $user): string
    {
        $now = Carbon::now();
        $claims = Context::get("__auth.guards.{$this->name}.claims", []);
        $token = $this->jwtManager->encode(array_merge([
            'sub' => $user->getAuthIdentifier(),
            'iat' => $now->copy()->timestamp,
            'exp' => $now->copy()->addMinutes($this->ttl)->timestamp,
        ], $claims));

        // if there's no token, then set cache key to `default`
        Context::set(
            $this->getContextKey($this->parseToken() ? $token : null),
            $user
        );

        return $token;
    }

    public function getContextKey(?string $token = null): string
    {
        if (! $token) {
            return "__auth.guards.{$this->name}.result.default";
        }

        return "__auth.guards.{$this->name}.result." . md5($token);
    }

    public function user(): ?Authenticatable
    {
        $token = $this->parseToken();
        // cache user in context
        if (Context::has($contextKey = $this->getContextKey($token))) {
            return Context::get($contextKey);
        }

        if (! $token) {
            return null;
        }

        $user = null;
        try {
            $payload = $this->jwtManager->decode($token);
            $sub = $payload['sub'] ?? null;
            $user = $sub ? $this->provider->retrieveById($sub) : null;

            Context::set($contextKey, $user);
        } catch (Throwable $exception) {
            Context::set($contextKey, null);
        }

        return $user;
    }

    /**
     * Add any custom claims.
     *
     * @return $this
     */
    public function claims(array $claims): static
    {
        $contextKey = "__auth.guards.{$this->name}.claims";
        if ($contextClaims = Context::get($contextKey)) {
            $claims = array_merge($contextClaims, $claims);
        }

        Context::set($contextKey, $claims);

        return $this;
    }

    public function getPayload(): array
    {
        try {
            return $this->jwtManager
                ->decode($this->parseToken());
        } catch (Throwable $exception) {
        }

        return [];
    }

    public function refresh(): ?string
    {
        if (! $token = $this->parseToken()) {
            return null;
        }

        Context::set($this->getContextKey($token), null);

        return $this->jwtManager->refresh($token);
    }

    /**
     * Log a user into the application using their credentials.
     */
    public function once(array $credentials = []): bool
    {
        return $this->attempt($credentials, true);
    }

    /**
     * Log the given User into the application.
     *
     * @return bool
     */
    public function onceUsingId(mixed $id): Authenticatable|bool
    {
        if ($user = $this->provider->retrieveById($id)) {
            $this->login($user);

            return true;
        }

        return false;
    }

    public function logout(): bool
    {
        $token = $this->parseToken();
        Context::set($this->getContextKey($token), null);

        if ($token) {
            $this->jwtManager->invalidate($token);
        }

        return true;
    }

    public function setUser(Authenticatable $user): void
    {
        Context::set($this->getContextKey(), $user);
    }
}
