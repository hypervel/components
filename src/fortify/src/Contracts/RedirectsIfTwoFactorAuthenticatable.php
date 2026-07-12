<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Contracts;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface RedirectsIfTwoFactorAuthenticatable
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response;
}
