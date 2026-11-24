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
            return max(1, RequestContext::getOrNull()?->integer($pageName, 1, strict: true) ?? 1);
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
