<?php

declare(strict_types=1);

namespace Hypervel\View\Middleware;

use Closure;
use Hyperf\Contract\SessionInterface;
use Hypervel\Support\ViewErrorBag;
use Hypervel\View\Contracts\Factory as ViewFactory;

class ShareErrorsFromSession
{
    /**
     * Create a new error binder instance.
     */
    public function __construct(
        protected ViewFactory $view,
        protected SessionInterface $session,
    ) {
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
            'errors',
            $this->session->get('errors') ?: new ViewErrorBag()
        );

        // Putting the errors in the view for every view allows the developer to just
        // assume that some errors are always available, which is convenient since
        // they don't have to continually run checks for the presence of errors.

        return $next($request);
    }
}
