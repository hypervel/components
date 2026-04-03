<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustHosts
{
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
            Request::setTrustedHosts(array_filter($this->hosts()));
        }

        return $next($request);
    }

    /**
     * Specify the hosts that should always be trusted.
     *
     * @param array<int, string>|(Closure(): array<int, string>) $hosts
     */
    public static function at(array|Closure $hosts, bool $subdomains = true): void
    {
        static::$alwaysTrust = $hosts;
        static::$subdomains = $subdomains;
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
        if ($host = parse_url($this->app['config']->get('app.url'), PHP_URL_HOST)) {
            return '^(.+\.)?' . preg_quote($host) . '$';
        }

        return null;
    }

    /**
     * Flush the state of the middleware.
     */
    public static function flushState(): void
    {
        static::$alwaysTrust = null;
        static::$subdomains = null;
    }
}
