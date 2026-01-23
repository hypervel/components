<?php

declare(strict_types=1);

namespace Hypervel\Router\Middleware;

use Hypervel\Support\Arr;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Router\Exceptions\InvalidSignatureException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ValidateSignature implements MiddlewareInterface
{
    /**
     * The names of the parameters that should be ignored.
     *
     * @var array<int, string>
     */
    protected array $except = [];

    /**
     * The globally ignored parameters.
     */
    protected static array $neverValidate = [];

    public function __construct(
        protected RequestContract $request
    ) {
    }

    /**
     * Specify that the URL signature is for a relative URL.
     */
    public static function relative(array|string $ignore = []): string
    {
        $ignore = Arr::wrap($ignore);

        return static::class . ':' . implode(',', empty($ignore) ? ['relative'] : ['relative', ...$ignore]);
    }

    /**
     * Specify that the URL signature is for an absolute URL.
     */
    public static function absolute(array|string $ignore = []): string
    {
        $ignore = Arr::wrap($ignore);

        return empty($ignore)
            ? static::class
            : static::class . ':' . implode(',', $ignore);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler, string ...$args): ResponseInterface
    {
        [$relative, $ignore] = $this->parseArguments($args);

        if ($this->request->hasValidSignatureWhileIgnoring($ignore, ! $relative)) {
            return $handler->handle($request);
        }

        throw new InvalidSignatureException();
    }

    /**
     * Parse the additional arguments given to the middleware.
     */
    protected function parseArguments(array $args): array
    {
        $relative = ! empty($args) && $args[0] === 'relative';

        if ($relative) {
            array_shift($args);
        }

        $ignore = array_merge($this->except, $args);

        return [$relative, array_merge($ignore, static::$neverValidate)];
    }

    /**
     * Indicate that the given parameters should be ignored during signature validation.
     */
    public static function except(array|string $parameters): void
    {
        static::$neverValidate = array_values(array_unique(
            array_merge(static::$neverValidate, Arr::wrap($parameters))
        ));
    }
}
