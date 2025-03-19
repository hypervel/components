<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Request;
use Hypervel\Cookie\Cookie;
use Hypervel\Encryption\Contracts\Encrypter;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Hypervel\Foundation\Http\Middleware\Concerns\ExcludesPaths;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Session\TokenMismatchException;
use Hypervel\Support\Traits\InteractsWithTime;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class VerifyCsrfToken implements MiddlewareInterface
{
    use InteractsWithTime;
    use ExcludesPaths;

    /**
     * The URIs that should be excluded.
     *
     * @var array<int, string>
     */
    protected array $except = [];

    /**
     * The globally ignored URIs that should be excluded from CSRF verification.
     */
    protected static array $neverVerify = [];

    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     */
    protected bool $addHttpCookie = true;

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ContainerInterface $app,
        protected ConfigInterface $config,
        protected Encrypter $encrypter,
        protected Request $request
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isReading($request)
            || $this->runningUnitTests()
            || $this->inExceptArray($request)
            || $this->tokensMatch()
        ) {
            $response = $handler->handle($request);
            if ($this->shouldAddXsrfTokenCookie()) {
                $response = $this->addCookieToResponse($response);
            }

            return $response;
        }

        throw new TokenMismatchException('CSRF token mismatch.');
    }

    /**
     * Determine if the HTTP request uses a ‘read’ verb.
     */
    protected function isReading(ServerRequestInterface $request): bool
    {
        return in_array($request->getMethod(), ['HEAD', 'GET', 'OPTIONS']);
    }

    /**
     * Determine if the application is running unit tests.
     */
    protected function runningUnitTests(): bool
    {
        if (! $this->app instanceof ApplicationContract) {
            return false;
        }

        return $this->app->runningUnitTests();
    }

    /**
     * Get the URIs that should be excluded.
     */
    public function getExcludedPaths(): array
    {
        return array_merge($this->except, static::$neverVerify);
    }

    /**
     * Determine if the session and input CSRF tokens match.
     */
    protected function tokensMatch(): bool
    {
        $token = $this->getTokenFromRequest();
        $sessionToken = $this->app->get(SessionContract::class)->token();

        return is_string($sessionToken)
            && is_string($token)
            && hash_equals($sessionToken, $token);
    }

    /**
     * Get the CSRF token from the request.
     */
    protected function getTokenFromRequest(): ?string
    {
        return $this->request->input('_token')
            ?? $this->request->header('X-CSRF-TOKEN')
            ?? null;
    }

    /**
     * Determine if the cookie should be added to the response.
     */
    public function shouldAddXsrfTokenCookie(): bool
    {
        return $this->addHttpCookie;
    }

    /**
     * Add the CSRF token to the response cookies.
     */
    protected function addCookieToResponse(ResponseInterface $response): ResponseInterface
    {
        /* @phpstan-ignore-next-line */
        return $response->withCookie(
            $this->newCookie($this->config->get('session', []))
        );
    }

    /**
     * Create a new "XSRF-TOKEN" cookie that contains the CSRF token.
     */
    protected function newCookie(array $config): Cookie
    {
        return new Cookie(
            'XSRF-TOKEN',
            $this->app->get(SessionContract::class)->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'] ?? '/',
            $config['domain'] ?? '',
            $config['secure'] ?? false,
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }

    /**
     * Indicate that the given URIs should be excluded from CSRF verification.
     */
    public static function except(array|string $uris): void
    {
        static::$neverVerify = array_values(array_unique(
            array_merge(static::$neverVerify, Arr::wrap($uris))
        ));
    }

    /**
     * Flush the state of the middleware.
     */
    public static function flushState(): void
    {
        static::$neverVerify = [];
    }
}
