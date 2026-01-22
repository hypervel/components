<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Psr\Container\ContainerInterface;

class PaginationState
{
    /**
     * Bind the pagination state resolvers using the given application container as a base.
     */
    public static function resolveUsing(ContainerInterface $app): void
    {
        Paginator::viewFactoryResolver(fn () => $app->get('view'));

        Paginator::currentPathResolver(fn () => $app->get('request')->url());

        Paginator::currentPageResolver(function (string $pageName = 'page') use ($app): int {
            $page = $app->get('request')->input($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });

        Paginator::queryStringResolver(fn () => $app->get('request')->query());

        CursorPaginator::currentCursorResolver(function (string $cursorName = 'cursor') use ($app): ?Cursor {
            return Cursor::fromEncoded($app->get('request')->input($cursorName));
        });
    }
}
