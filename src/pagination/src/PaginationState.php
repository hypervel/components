<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\View\Factory;
use Hypervel\Support\Attributes\BootOnly;

class PaginationState
{
    /**
     * Bind the pagination state resolvers using the given application container as a base.
     *
     * The request resolvers read RequestContext on each invocation, while the
     * lazy view resolver captures the worker-lifetime container.
     */
    #[BootOnly]
    public static function resolveUsing(Container $app): void
    {
        Paginator::viewFactoryResolver(fn (): Factory => $app->make('view'));

        Paginator::currentPathResolver(static function (): string {
            $request = RequestContext::getOrNull();

            return $request?->url() ?? '/';
        });

        Paginator::currentPageResolver(static function (string $pageName = 'page'): int {
            $request = RequestContext::getOrNull();

            if ($request === null) {
                return 1;
            }

            // Use the filtered value: a numeric Stringable passes the filter, but casting it to int fails.
            $page = filter_var($request->input($pageName), FILTER_VALIDATE_INT);

            return $page !== false && $page >= 1 ? $page : 1;
        });

        Paginator::queryStringResolver(static function (): array {
            $request = RequestContext::getOrNull();

            return $request?->query() ?? [];
        });

        CursorPaginator::currentCursorResolver(static function (string $cursorName = 'cursor'): ?Cursor {
            $request = RequestContext::getOrNull();

            return $request === null
                ? null
                : Cursor::fromEncoded($request->input($cursorName));
        });
    }
}
