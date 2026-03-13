<?php

declare(strict_types=1);

namespace Hypervel\Routing\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Routing\Exceptions\InvalidSignatureException;
use Hypervel\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class ValidateSignature
{
    /**
     * The names of the parameters that should be ignored.
     *
     * @var array<int, string>
     */
    protected array $ignore = [
    ];

    /**
     * The globally ignored parameters.
     */
    protected static array $neverValidate = [];

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

    /**
     * Handle an incoming request.
     *
     * @throws \Hypervel\Routing\Exceptions\InvalidSignatureException
     */
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        [$relative, $ignore] = $this->parseArguments($args);

        if ($request->hasValidSignatureWhileIgnoring($ignore, ! $relative)) {
            return $next($request);
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

        $ignore = array_merge(
            property_exists($this, 'except') ? $this->except : $this->ignore,
            $args
        );

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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$neverValidate = [];
    }
}
