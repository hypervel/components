<?php

declare(strict_types=1);

namespace Hypervel\Routing\Controllers;

use Closure;
use Hypervel\Support\Arr;

class Middleware
{
    /**
     * Create a new controller middleware definition.
     *
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
