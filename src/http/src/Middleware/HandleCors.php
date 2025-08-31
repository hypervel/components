<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Cors;
use Hypervel\Support\Str;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class HandleCors implements MiddlewareInterface
{
    protected array $config = [];

    public function __construct(
        protected ContainerInterface $container,
        protected ExceptionHandlerContract $exceptionHandler,
        protected RequestContract $request,
        protected Cors $cors,
    ) {
        $this->cors->setOptions(
            $this->config = $container->get(ConfigInterface::class)->get('cors', [])
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->hasMatchingPath($this->request)) {
            return $handler->handle($request);
        }

        if ($this->cors->isPreflightRequest($this->request)) {
            $response = $this->cors->handlePreflightRequest($this->request);
            return $this->cors->varyHeader($response, 'Access-Control-Request-Method');
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->exceptionHandler->afterResponse(
                fn (ResponseInterface $response) => $this->addRequestHeaders($response)
            );

            throw $e;
        }

        return $this->addRequestHeaders($response);
    }

    /**
     * Add CORS headers to the response.
     */
    protected function addRequestHeaders(ResponseInterface $response): ResponseInterface
    {
        if ($this->request->getMethod() === 'OPTIONS') {
            $response = $this->cors->varyHeader($response, 'Access-Control-Request-Method');
        }

        return $this->cors->addActualRequestHeaders($response, $this->request);
    }

    /**
     * Get the path from the configuration to determine if the CORS service should run.
     */
    protected function hasMatchingPath(RequestContract $request): bool
    {
        $paths = $this->getPathsByHost($request->getHost());
        foreach ($paths as $path) {
            if ($path !== '/') {
                $path = trim($path, '/');
            }

            if (Str::is($path, $request->fullUrl()) || Str::is($path, $request->decodedPath())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the CORS paths for the given host.
     */
    protected function getPathsByHost(string $host): array
    {
        $paths = $this->config['paths'] ?? [];

        if (isset($paths[$host])) {
            return $paths[$host];
        }

        return array_filter($paths, function ($path) {
            return is_string($path);
        });
    }
}
