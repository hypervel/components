<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustHosts
{
    // Never matches; keeps trusted-host validation enabled when no hosts are trusted.
    private const REJECT_ALL_HOST_PATTERN = '(?!)';

    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * The trusted hosts that have been configured to always be trusted.
     *
     * @var null|array<int, string>|(Closure(): array<int, string>)
     */
    protected static array|Closure|null $alwaysTrust = null;

    /**
     * The closure used to resolve trusted hosts for the current request.
     *
     * @var null|Closure(Request): array<int, string>
     */
    protected static ?Closure $hostsResolver = null;

    /**
     * Indicates whether subdomains of the application URL should be trusted.
     */
    protected static ?bool $subdomains = null;

    /**
     * Create a new middleware instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get the host patterns that should be trusted.
     */
    public function hosts(): array
    {
        if (is_null(static::$alwaysTrust)) {
            return [$this->allSubdomainsOfApplicationUrl()];
        }

        $hosts = match (true) {
            is_array(static::$alwaysTrust) => static::$alwaysTrust,
            static::$alwaysTrust instanceof Closure => (static::$alwaysTrust)(),
        };

        if (static::$subdomains) {
            $hosts[] = $this->allSubdomainsOfApplicationUrl();
        }

        return $hosts;
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSpecifyTrustedHosts()) {
            Request::setTrustedHosts($this->resolveTrustedHostPatterns($request));
        }

        return $next($request);
    }

    /**
     * Get the trusted host patterns for the current request.
     *
     * @return array<int, string>
     */
    protected function resolveTrustedHostPatterns(Request $request): array
    {
        $resolved = static::$hostsResolver instanceof Closure
            ? (static::$hostsResolver)($request)
            : $this->hosts();

        $hosts = is_array($resolved)
            ? array_values(array_filter($resolved, static fn (mixed $host): bool => is_string($host) && $host !== ''))
            : [];

        return $hosts === []
            ? [self::REJECT_ALL_HOST_PATTERN]
            : $hosts;
    }

    /**
     * Specify the hosts that should always be trusted.
     *
     * Boot-only. The list persists in static properties for the worker lifetime
     * and applies to every subsequent request.
     *
     * @param array<int, string>|(Closure(): array<int, string>) $hosts
     */
    public static function at(array|Closure $hosts, bool $subdomains = true): void
    {
        static::$alwaysTrust = $hosts;
        static::$subdomains = $subdomains;
    }

    /**
     * Register a closure that resolves trusted hosts for the current request.
     *
     * Boot-only. The callback persists in static state for the worker lifetime
     * and fully replaces the static trusted host list for every subsequent request.
     *
     * @param null|Closure(Request): array<int, string> $callback
     */
    public static function resolveHostsUsing(?Closure $callback): void
    {
        static::$hostsResolver = $callback;
    }

    /**
     * Determine if the application should specify trusted hosts.
     */
    protected function shouldSpecifyTrustedHosts(): bool
    {
        return ! $this->app->environment('local')
               && ! $this->app->runningUnitTests();
    }

    /**
     * Get a regular expression matching the application URL and all of its subdomains.
     */
    protected function allSubdomainsOfApplicationUrl(): ?string
    {
        if ($host = parse_url($this->app->make('config')->string('app.url'), PHP_URL_HOST)) {
            return '^(.+\.)?' . preg_quote($host) . '$';
        }

        return null;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$alwaysTrust = null;
        static::$hostsResolver = null;
        static::$subdomains = null;
    }
}
