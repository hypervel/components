<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

use Hypervel\Http\Request;
use Psr\Http\Message\ResponseInterface;

interface Responsable
{
    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): ResponseInterface;
}
