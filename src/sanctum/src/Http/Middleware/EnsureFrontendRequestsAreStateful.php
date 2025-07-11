<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Middleware;

use Hyperf\Collection\Collection;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hypervel\Support\Str;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class EnsureFrontendRequestsAreStateful implements MiddlewareInterface
{
    /**
     * The middleware to apply to stateful requests.
     *
     * @var array<class-string>
     */
    protected array $frontendMiddleware = [];

    public function __construct(
        protected ContainerInterface $container,
        protected RequestInterface $request,
        protected HttpResponse $response
    ) {
        $this->configureFrontendMiddleware();
    }

    /**
     * Process the incoming request.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->configureSecureCookieSessions();

        if (static::fromFrontend($this->request)) {
            // Mark request as stateful
            $request = $request->withAttribute('sanctum', true);

            // Apply frontend middleware chain
            return $this->applyFrontendMiddleware($request, $handler);
        }

        return $handler->handle($request);
    }

    /**
     * Configure secure cookie sessions.
     */
    protected function configureSecureCookieSessions(): void
    {
        config([
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);
    }

    /**
     * Configure the middleware that should be applied to requests from the "frontend".
     */
    protected function configureFrontendMiddleware(): void
    {
        $this->frontendMiddleware = array_values(array_filter([
            config('sanctum.middleware.add_queued_cookies'),
            config('sanctum.middleware.start_session'),
            config('sanctum.middleware.verify_csrf_token'),
            config('sanctum.middleware.authenticate_session'),
        ]));
    }

    /**
     * Apply the frontend middleware to the request.
     */
    protected function applyFrontendMiddleware(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Build middleware chain in reverse order
        $next = $handler;

        foreach (array_reverse($this->frontendMiddleware) as $middleware) {
            if (! $middleware) {
                continue;
            }

            $next = new class($this->container, $middleware, $next) implements RequestHandlerInterface {
                public function __construct(
                    private ContainerInterface $container,
                    private string $middleware,
                    private RequestHandlerInterface $next
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    /** @var MiddlewareInterface $instance */
                    $instance = $this->container->get($this->middleware);
                    return $instance->process($request, $this->next);
                }
            };
        }

        return $next->handle($request);
    }

    /**
     * Determine if the given request is from the first-party application frontend.
     */
    public static function fromFrontend(RequestInterface $request): bool
    {
        $referer = $request->header('referer');
        $origin = $request->header('origin');

        $domain = $referer ?: $origin;

        if (is_null($domain)) {
            return false;
        }

        $domain = Str::replaceFirst('https://', '', $domain);
        $domain = Str::replaceFirst('http://', '', $domain);
        $domain = Str::endsWith($domain, '/') ? $domain : "{$domain}/";

        $stateful = array_filter(config('sanctum.stateful', []));

        return Str::is(Collection::make($stateful)->map(function ($uri) {
            return trim($uri) . '/*';
        })->all(), $domain);
    }
}
