<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Hypervel\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Http\Middleware\TrustHosts;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Routing\Middleware\ValidateSignature;
use Hypervel\Session\Middleware\AuthenticateSession;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

class Middleware
{
    /**
     * The user defined global middleware stack.
     */
    protected array $global = [];

    /**
     * The middleware that should be prepended to the global middleware stack.
     */
    protected array $prepends = [];

    /**
     * The middleware that should be appended to the global middleware stack.
     */
    protected array $appends = [];

    /**
     * The middleware that should be removed from the global middleware stack.
     */
    protected array $removals = [];

    /**
     * The middleware that should be replaced in the global middleware stack.
     */
    protected array $replacements = [];

    /**
     * The user defined middleware groups.
     */
    protected array $groups = [];

    /**
     * The middleware that should be prepended to the specified groups.
     */
    protected array $groupPrepends = [];

    /**
     * The middleware that should be appended to the specified groups.
     */
    protected array $groupAppends = [];

    /**
     * The middleware that should be removed from the specified groups.
     */
    protected array $groupRemovals = [];

    /**
     * The middleware that should be replaced in the specified groups.
     */
    protected array $groupReplacements = [];

    /**
     * The Folio / page middleware for the application.
     */
    protected array $pageMiddleware = [];

    /**
     * Indicates if the "trust hosts" middleware is enabled.
     */
    protected bool $trustHosts = false;

    /**
     * Indicates if Sanctum's frontend state middleware is enabled.
     */
    protected bool $statefulApi = false;

    /**
     * Indicates the API middleware group's rate limiter.
     */
    protected ?string $apiLimiter = null;

    /**
     * Indicates if Redis throttling should be applied.
     */
    protected bool $throttleWithRedis = false;

    /**
     * Indicates if sessions should be authenticated for the "web" middleware group.
     */
    protected bool $authenticatedSessions = false;

    /**
     * The custom middleware aliases.
     */
    protected array $customAliases = [];

    /**
     * The custom middleware priority definition.
     */
    protected array $priority = [];

    /**
     * The middleware to prepend to the middleware priority definition.
     */
    protected array $prependPriority = [];

    /**
     * The middleware to append to the middleware priority definition.
     */
    protected array $appendPriority = [];

    /**
     * Prepend middleware to the application's global middleware stack.
     */
    public function prepend(array|string $middleware): static
    {
        $this->prepends = array_merge(
            Arr::wrap($middleware),
            $this->prepends
        );

        return $this;
    }

    /**
     * Append middleware to the application's global middleware stack.
     */
    public function append(array|string $middleware): static
    {
        $this->appends = array_merge(
            $this->appends,
            Arr::wrap($middleware)
        );

        return $this;
    }

    /**
     * Remove middleware from the application's global middleware stack.
     */
    public function remove(array|string $middleware): static
    {
        $this->removals = array_merge(
            $this->removals,
            Arr::wrap($middleware)
        );

        return $this;
    }

    /**
     * Specify a middleware that should be replaced with another middleware.
     */
    public function replace(string $search, string $replace): static
    {
        $this->replacements[$search] = $replace;

        return $this;
    }

    /**
     * Define the global middleware for the application.
     */
    public function use(array $middleware): static
    {
        $this->global = $middleware;

        return $this;
    }

    /**
     * Define a middleware group.
     */
    public function group(string $group, array $middleware): static
    {
        $this->groups[$group] = $middleware;

        return $this;
    }

    /**
     * Prepend the given middleware to the specified group.
     */
    public function prependToGroup(string $group, array|string $middleware): static
    {
        $this->groupPrepends[$group] = array_merge(
            Arr::wrap($middleware),
            $this->groupPrepends[$group] ?? []
        );

        return $this;
    }

    /**
     * Append the given middleware to the specified group.
     */
    public function appendToGroup(string $group, array|string $middleware): static
    {
        $this->groupAppends[$group] = array_merge(
            $this->groupAppends[$group] ?? [],
            Arr::wrap($middleware)
        );

        return $this;
    }

    /**
     * Remove the given middleware from the specified group.
     */
    public function removeFromGroup(string $group, array|string $middleware): static
    {
        $this->groupRemovals[$group] = array_merge(
            Arr::wrap($middleware),
            $this->groupRemovals[$group] ?? []
        );

        return $this;
    }

    /**
     * Replace the given middleware in the specified group with another middleware.
     */
    public function replaceInGroup(string $group, string $search, string $replace): static
    {
        $this->groupReplacements[$group][$search] = $replace;

        return $this;
    }

    /**
     * Modify the middleware in the "web" group.
     */
    public function web(array|string $append = [], array|string $prepend = [], array|string $remove = [], array $replace = []): static
    {
        return $this->modifyGroup('web', $append, $prepend, $remove, $replace);
    }

    /**
     * Modify the middleware in the "api" group.
     */
    public function api(array|string $append = [], array|string $prepend = [], array|string $remove = [], array $replace = []): static
    {
        return $this->modifyGroup('api', $append, $prepend, $remove, $replace);
    }

    /**
     * Modify the middleware in the given group.
     */
    protected function modifyGroup(string $group, array|string $append, array|string $prepend, array|string $remove, array $replace): static
    {
        if (! empty($append)) {
            $this->appendToGroup($group, $append);
        }

        if (! empty($prepend)) {
            $this->prependToGroup($group, $prepend);
        }

        if (! empty($remove)) {
            $this->removeFromGroup($group, $remove);
        }

        if (! empty($replace)) {
            foreach ($replace as $search => $replace) {
                $this->replaceInGroup($group, $search, $replace);
            }
        }

        return $this;
    }

    /**
     * Register the Folio / page middleware for the application.
     */
    public function pages(array $middleware): static
    {
        $this->pageMiddleware = $middleware;

        return $this;
    }

    /**
     * Register additional middleware aliases.
     */
    public function alias(array $aliases): static
    {
        $this->customAliases = $aliases;

        return $this;
    }

    /**
     * Define the middleware priority for the application.
     */
    public function priority(array $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Prepend middleware to the priority middleware.
     */
    public function prependToPriorityList(array|string $before, string $prepend): static
    {
        $this->prependPriority[$prepend] = $before;

        return $this;
    }

    /**
     * Append middleware to the priority middleware.
     */
    public function appendToPriorityList(array|string $after, string $append): static
    {
        $this->appendPriority[$append] = $after;

        return $this;
    }

    /**
     * Get the global middleware.
     */
    public function getGlobalMiddleware(): array
    {
        $middleware = $this->global ?: array_values(array_filter([
            \Hypervel\Http\Middleware\ValidatePathEncoding::class,
            InvokeDeferredCallbacks::class,
            $this->trustHosts ? \Hypervel\Http\Middleware\TrustHosts::class : null,
            \Hypervel\Http\Middleware\TrustProxies::class,
            \Hypervel\Http\Middleware\HandleCors::class,
            \Hypervel\Http\Middleware\ValidatePostSize::class,
            \Hypervel\Foundation\Http\Middleware\TrimStrings::class,
            \Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]));

        $middleware = array_map(function ($middleware) {
            return $this->replacements[$middleware] ?? $middleware;
        }, $middleware);

        return array_values(array_filter(
            array_diff(
                array_unique(array_merge($this->prepends, $middleware, $this->appends)),
                $this->removals
            )
        ));
    }

    /**
     * Get the middleware groups.
     */
    public function getMiddlewareGroups(): array
    {
        $middleware = [
            /* @phpstan-ignore arrayValues.list */
            'web' => array_values(array_filter([
                \Hypervel\Cookie\Middleware\EncryptCookies::class,
                \Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Hypervel\Session\Middleware\StartSession::class,
                \Hypervel\View\Middleware\ShareErrorsFromSession::class,
                \Hypervel\Foundation\Http\Middleware\PreventRequestForgery::class,
                \Hypervel\Routing\Middleware\SubstituteBindings::class,
                $this->authenticatedSessions ? 'auth.session' : null,
            ])),

            'api' => array_values(array_filter([
                $this->statefulApi ? \Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class : null,
                $this->apiLimiter ? 'throttle:' . $this->apiLimiter : null,
                \Hypervel\Routing\Middleware\SubstituteBindings::class,
            ])),
        ];

        $middleware = array_merge($middleware, $this->groups);

        foreach ($middleware as $group => $groupedMiddleware) {
            foreach ($groupedMiddleware as $index => $groupMiddleware) {
                if (isset($this->groupReplacements[$group][$groupMiddleware])) {
                    $middleware[$group][$index] = $this->groupReplacements[$group][$groupMiddleware];
                }
            }
        }

        foreach ($this->groupRemovals as $group => $removals) {
            $middleware[$group] = array_values(array_filter(
                array_diff($middleware[$group] ?? [], $removals)
            ));
        }

        foreach ($this->groupPrepends as $group => $prepends) {
            $middleware[$group] = array_values(array_filter(
                array_unique(array_merge($prepends, $middleware[$group] ?? []))
            ));
        }

        foreach ($this->groupAppends as $group => $appends) {
            $middleware[$group] = array_values(array_filter(
                array_unique(array_merge($middleware[$group] ?? [], $appends))
            ));
        }

        return $middleware;
    }

    /**
     * Configure where guests are redirected by the "auth" middleware.
     */
    public function redirectGuestsTo(callable|string $redirect): static
    {
        return $this->redirectTo(guests: $redirect);
    }

    /**
     * Configure where users are redirected by the "guest" middleware.
     */
    public function redirectUsersTo(callable|string $redirect): static
    {
        return $this->redirectTo(users: $redirect);
    }

    /**
     * Configure where users are redirected by the authentication and guest middleware.
     */
    public function redirectTo(callable|string|null $guests = null, callable|string|null $users = null): static
    {
        $guests = is_string($guests) ? fn () => $guests : $guests;
        $users = is_string($users) ? fn () => $users : $users;

        if ($guests) {
            Authenticate::redirectUsing($guests);
            AuthenticateSession::redirectUsing($guests);
            AuthenticationException::redirectUsing($guests);
        }

        if ($users) {
            RedirectIfAuthenticated::redirectUsing($users);
        }

        return $this;
    }

    /**
     * Configure the cookie encryption middleware.
     *
     * @param array<int, string> $except
     * @param array<int, string> $only
     */
    public function encryptCookies(array $except = [], array $only = []): static
    {
        if ($except !== []) {
            EncryptCookies::except($except);
        }

        if ($only !== []) {
            EncryptCookies::only($only);
        }

        return $this;
    }

    /**
     * Configure the request forgery prevention middleware.
     */
    public function preventRequestForgery(array $except = [], bool $originOnly = false, bool $allowSameSite = false): static
    {
        if (! empty($except)) {
            PreventRequestForgery::except($except);
        }

        PreventRequestForgery::useOriginOnly($originOnly);
        PreventRequestForgery::allowSameSite($allowSameSite);

        return $this;
    }

    /**
     * Configure the CSRF token validation middleware.
     *
     * @deprecated use preventRequestForgery() instead
     */
    public function validateCsrfTokens(array $except = []): static
    {
        return $this->preventRequestForgery($except);
    }

    /**
     * Configure the URL signature validation middleware.
     *
     * @param array<int, string> $except
     */
    public function validateSignatures(array $except = []): static
    {
        ValidateSignature::except($except);

        return $this;
    }

    /**
     * Configure the empty string conversion middleware.
     *
     * @param array<int, (Closure(\Hypervel\Http\Request): bool)> $except
     */
    public function convertEmptyStringsToNull(array $except = []): static
    {
        (new Collection($except))->each(fn (Closure $callback) => ConvertEmptyStringsToNull::skipWhen($callback));

        return $this;
    }

    /**
     * Configure the string trimming middleware.
     *
     * @param array<int, (Closure(\Hypervel\Http\Request): bool)|string> $except
     */
    public function trimStrings(array $except = []): static
    {
        [$skipWhen, $except] = (new Collection($except))->partition(fn ($value) => $value instanceof Closure);

        $skipWhen->each(fn (Closure $callback) => TrimStrings::skipWhen($callback));

        TrimStrings::except($except->all());

        return $this;
    }

    /**
     * Indicate that the trusted host middleware should be enabled.
     *
     * @param null|array<int, string>|(callable(): array<int, string>) $at
     */
    public function trustHosts(array|callable|null $at = null, bool $subdomains = true): static
    {
        $this->trustHosts = true;

        if (! is_null($at)) {
            TrustHosts::at($at, $subdomains);
        }

        return $this;
    }

    /**
     * Configure the trusted proxies for the application.
     *
     * @param null|array<int, string>|string $at
     */
    public function trustProxies(array|string|null $at = null, ?int $headers = null): static
    {
        if (! is_null($at)) {
            TrustProxies::at($at);
        }

        if (! is_null($headers)) {
            TrustProxies::withHeaders($headers);
        }

        return $this;
    }

    /**
     * Configure the middleware that prevents requests during maintenance mode.
     *
     * @param array<int, string> $except
     */
    public function preventRequestsDuringMaintenance(array $except = []): static
    {
        PreventRequestsDuringMaintenance::except($except);

        return $this;
    }

    /**
     * Indicate that Sanctum's frontend state middleware should be enabled.
     */
    public function statefulApi(): static
    {
        $this->statefulApi = true;

        return $this;
    }

    /**
     * Indicate that the API middleware group's throttling middleware should be enabled.
     */
    public function throttleApi(string $limiter = 'api', bool $redis = false): static
    {
        $this->apiLimiter = $limiter;

        if ($redis) {
            $this->throttleWithRedis();
        }

        return $this;
    }

    /**
     * Indicate that Hypervel's throttling middleware should use Redis.
     */
    public function throttleWithRedis(): static
    {
        $this->throttleWithRedis = true;

        return $this;
    }

    /**
     * Indicate that sessions should be authenticated for the "web" middleware group.
     */
    public function authenticateSessions(): static
    {
        $this->authenticatedSessions = true;

        return $this;
    }

    /**
     * Get the Folio / page middleware for the application.
     */
    public function getPageMiddleware(): array
    {
        return $this->pageMiddleware;
    }

    /**
     * Get the middleware aliases.
     */
    public function getMiddlewareAliases(): array
    {
        return array_merge($this->defaultAliases(), $this->customAliases);
    }

    /**
     * Get the default middleware aliases.
     */
    protected function defaultAliases(): array
    {
        return [
            'auth' => \Hypervel\Auth\Middleware\Authenticate::class,
            'auth.session' => \Hypervel\Session\Middleware\AuthenticateSession::class,
            'cache.headers' => \Hypervel\Http\Middleware\SetCacheHeaders::class,
            'can' => \Hypervel\Auth\Middleware\Authorize::class,
            'precognitive' => \Hypervel\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            'signed' => \Hypervel\Routing\Middleware\ValidateSignature::class,
            'throttle' => \Hypervel\Routing\Middleware\ThrottleRequests::class,
        ];
    }

    /**
     * Get the middleware priority for the application.
     */
    public function getMiddlewarePriority(): array
    {
        return $this->priority;
    }

    /**
     * Get the middleware to prepend to the middleware priority definition.
     */
    public function getMiddlewarePriorityPrepends(): array
    {
        return $this->prependPriority;
    }

    /**
     * Get the middleware to append to the middleware priority definition.
     */
    public function getMiddlewarePriorityAppends(): array
    {
        return $this->appendPriority;
    }
}
