<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ExceptionResponse implements Responsable
{
    protected ?string $component = null;

    /** @var array<string, mixed> */
    protected array $props = [];

    protected bool $includeSharedData = false;

    protected ?string $rootView = null;

    /** @var null|class-string<Middleware> */
    protected ?string $middlewareClass = null;

    /**
     * Create a new exception response instance.
     */
    public function __construct(
        public readonly Throwable $exception,
        public readonly Request $request,
        public readonly Response $response,
        protected readonly Router $router,
        protected readonly KernelContract $kernel,
    ) {
    }

    /**
     * Render the exception with the given Inertia component.
     *
     * @param array<string, mixed> $props
     */
    public function render(string $component, array $props = []): static
    {
        $this->component = $component;
        $this->props = $props;

        return $this;
    }

    /**
     * Use the given Inertia middleware.
     *
     * @param class-string<Middleware> $middlewareClass
     */
    public function usingMiddleware(string $middlewareClass): static
    {
        $this->middlewareClass = $middlewareClass;

        return $this;
    }

    /**
     * Include the middleware shared data.
     */
    public function withSharedData(): static
    {
        $this->includeSharedData = true;

        return $this;
    }

    /**
     * Set the root view.
     */
    public function rootView(string $rootView): static
    {
        $this->rootView = $rootView;

        return $this;
    }

    /**
     * Return the response status code.
     */
    public function statusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        if ($this->component === null) {
            return $this->response;
        }

        $middleware = $this->resolveMiddleware();

        if ($middleware) {
            Inertia::version(fn () => $middleware->version($this->request));
            Inertia::setRootView($this->rootView ?? $middleware->rootView($this->request));
        } elseif ($this->rootView) {
            Inertia::setRootView($this->rootView);
        }

        if ($this->includeSharedData && $middleware) {
            Inertia::share($middleware->share($this->request));

            foreach ($middleware->shareOnce($this->request) as $key => $value) {
                if ($value instanceof OnceProp) {
                    Inertia::share($key, $value);
                } else {
                    Inertia::shareOnce($key, $value);
                }
            }
        }

        return Inertia::render($this->component, $this->props)
            ->toResponse($this->request)
            ->setStatusCode($this->response->getStatusCode());
    }

    /**
     * Resolve the Inertia middleware for the request.
     */
    protected function resolveMiddleware(): ?Middleware
    {
        if ($this->middlewareClass) {
            return app($this->middlewareClass);
        }

        $class = $this->resolveMiddlewareFromRoute() ?? $this->resolveMiddlewareFromKernel();

        if ($class) {
            return app($class);
        }

        return null;
    }

    /**
     * Resolve the Inertia middleware from the route.
     *
     * @return null|class-string<Middleware>
     */
    protected function resolveMiddlewareFromRoute(): ?string
    {
        $route = $this->request->route();

        if (! $route) {
            return null;
        }

        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $class = head(explode(':', $middleware));

            if (is_a($class, Middleware::class, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Resolve the Inertia middleware from the HTTP kernel.
     *
     * @return null|class-string<Middleware>
     */
    protected function resolveMiddlewareFromKernel(): ?string
    {
        foreach ($this->kernel->getMiddlewareGroups() as $group) {
            foreach ($group as $middleware) {
                if (is_string($middleware) && is_a($middleware, Middleware::class, true)) {
                    return $middleware;
                }
            }
        }

        return null;
    }
}
