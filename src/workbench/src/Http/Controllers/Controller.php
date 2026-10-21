<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers;

use Hypervel\Routing\Controller as BaseController;
use Hypervel\Workbench\Workbench;

abstract class Controller extends BaseController
{
    /**
     * Get redirect to path after logged in.
     */
    protected function redirectToAfterLoggedIn(): ?string
    {
        $start = Workbench::config('start') ?? '/';
        $hasAuthentication = Workbench::config('auth') ?? false;

        return match (true) {
            $hasAuthentication === true && $start === '/' => route('dashboard', absolute: false),
            $hasAuthentication === true => $start,
            default => $start,
        };
    }
}
