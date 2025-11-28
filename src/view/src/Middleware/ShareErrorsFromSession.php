<?php

declare(strict_types=1);

namespace Hypervel\View\Middleware;

use Closure;
use Hypervel\View\Contracts\Factory as ViewFactory;
use Hypervel\Support\ViewErrorBag;

class ShareErrorsFromSession
{
    /**
     * The view factory implementation.
     */
    protected ViewFactory $view;

    /**
     * Create a new error binder instance.
     */
    public function __construct(ViewFactory $view)
    {
        $this->view = $view;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(mixed $request, Closure $next): mixed
    {
        // If the current session has an "errors" variable bound to it, we will share
        // its value with all view instances so the views can easily access errors
        // without having to bind. An empty bag is set when there aren't errors.
        $this->view->share(
            'errors', $request->session()->get('errors') ?: new ViewErrorBag
        );

        // Putting the errors in the view for every view allows the developer to just
        // assume that some errors are always available, which is convenient since
        // they don't have to continually run checks for the presence of errors.

        return $next($request);
    }
}
