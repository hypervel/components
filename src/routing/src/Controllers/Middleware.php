<?php

declare(strict_types=1);

namespace Hypervel\Routing\Controllers;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-type NextClosure Closure(Request): Response
 */
class Middleware
{
    /**
     * Create a new controller middleware definition.
     *
     * @param array|(Closure(Request, NextClosure): Response)|string $middleware
     * @param null|array<string> $only
     * @param null|array<string> $except
     */
    public function __construct(
        public Closure|string|array $middleware,
        public ?array $only = null,
        public ?array $except = null,
    ) {
    }

    /**
     * Specify the only controller methods the middleware should apply to.
     *
     * @return $this
     */
    public function only(array|string $only): static
    {
        $this->only = Arr::wrap($only);

        return $this;
    }

    /**
     * Specify the controller methods the middleware should not apply to.
     *
     * @return $this
     */
    public function except(array|string $except): static
    {
        $this->except = Arr::wrap($except);

        return $this;
    }
}
