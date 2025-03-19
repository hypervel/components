<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class TransformsRequest implements MiddlewareInterface
{
    protected array $except = [];

    protected static $skipCallbacks = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldSkip($request)) {
            return $handler->handle($request);
        }

        Context::set(
            ServerRequestInterface::class,
            $request = $this->processInput($request)
        );

        return $handler->handle($request);
    }

    public static function skipWhen(callable $callback): void
    {
        static::$skipCallbacks[] = $callback;
    }

    protected function shouldSkip(ServerRequestInterface $request): bool
    {
        foreach (static::$skipCallbacks as $callback) {
            if (call_user_func($callback, $request)) {
                return true;
            }
        }

        return false;
    }

    protected function processInput(ServerRequestInterface $request): ServerRequestInterface
    {
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $parsedBody = $this->processArray($parsedBody);
            $request = $request->withParsedBody($parsedBody);
        }

        $queryParams = $request->getQueryParams();
        $queryParams = $this->processArray($queryParams);

        return $request->withQueryParams($queryParams);
    }

    protected function processArray(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_string($value) && ! in_array($key, $this->except)) {
                $array[$key] = $this->processString($value);
            } elseif (is_array($value)) {
                $array[$key] = $this->processArray($value);
            }
        }

        return $array;
    }

    abstract protected function processString(string $value);
}
