<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Psr\Http\Message\ServerRequestInterface;

class PaginationState
{
    /**
     * Bind the pagination state resolvers using the given application container as a base.
     */
    public static function resolveUsing(Container $app): void
    {
        Paginator::viewFactoryResolver(fn () => $app->make('view'));

        Paginator::currentPathResolver(function () use ($app): string {
            if (! Context::has(ServerRequestInterface::class)) {
                return '/';
            }

            return $app->make('request')->url();
        });

        Paginator::currentPageResolver(function (string $pageName = 'page') use ($app): int {
            if (! Context::has(ServerRequestInterface::class)) {
                return 1;
            }

            $page = $app->make('request')->input($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });

        Paginator::queryStringResolver(function () use ($app): array {
            if (! Context::has(ServerRequestInterface::class)) {
                return [];
            }

            return $app->make('request')->query();
        });

        CursorPaginator::currentCursorResolver(function (string $cursorName = 'cursor') use ($app): ?Cursor {
            if (! Context::has(ServerRequestInterface::class)) {
                return null;
            }

            return Cursor::fromEncoded($app->make('request')->input($cursorName));
        });
    }
}
