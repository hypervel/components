<?php

declare(strict_types=1);

namespace Hypervel\Head\Inertia;

use Closure;
use Hypervel\Head\HeadManager;
use Hypervel\Http\Request;
use Hypervel\Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ShareHead
{
    /**
     * Create the head-sharing middleware.
     */
    public function __construct(protected HeadManager $head)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // A plain callable (rather than Inertia::always) keeps the elements
        // off the wire during partial reloads; the client retains the head
        // from the last full visit.
        Inertia::share(
            $this->head->inertiaProp(),
            fn (): array => $this->head->toInertiaElements(),
        );

        return $next($request);
    }
}
