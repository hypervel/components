<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Contracts\Support\Responsable;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectAsIntended implements Responsable
{
    public function __construct(
        public readonly string $name,
    ) {
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        return redirect()->intended(Fortify::redirects($this->name, request: $request));
    }
}
