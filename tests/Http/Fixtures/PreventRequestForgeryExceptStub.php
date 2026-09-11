<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Http\Request;

class PreventRequestForgeryExceptStub extends PreventRequestForgery
{
    /**
     * Determine if the request matches an excluded path.
     */
    public function checkInExceptArray(Request $request): bool
    {
        return $this->inExceptArray($request);
    }

    /**
     * Set the locally excluded paths.
     */
    public function setExcept(array $except): static
    {
        $this->except = $except;

        return $this;
    }
}
