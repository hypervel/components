<?php

declare(strict_types=1);

namespace Hypervel\View\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\ViewErrorBag;
use Hypervel\View\RequestSharedData;
use Symfony\Component\HttpFoundation\Response;

class ShareErrorsFromSession
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If the current session has an "errors" variable bound to it, we will share
        // its value with all view instances so the views can easily access errors
        // without having to bind. An empty bag is set when there aren't errors.
        $errors = $request->session()->get('errors') ?: new ViewErrorBag;

        // Putting the errors in the view for every view allows the developer to just
        // assume that some errors are always available, which is convenient since
        // they don't have to continually run checks for the presence of errors.

        return RequestSharedData::scope(
            ['errors' => $errors],
            fn (): Response => $next($request)
        );
    }
}
