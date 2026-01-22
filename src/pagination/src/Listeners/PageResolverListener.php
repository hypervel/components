<?php

declare(strict_types=1);

namespace Hypervel\Pagination\Listeners;

use Hyperf\Context\Context;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\Paginator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

class PageResolverListener implements ListenerInterface
{
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    /**
     * @return array<int, class-string>
     */
    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event): void
    {
        $container = $this->container;

        Paginator::currentPageResolver(function (string $pageName = 'page') use ($container): int {
            if (! Context::has(ServerRequestInterface::class)) {
                return 1;
            }

            $page = $container->get(RequestInterface::class)->input($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });

        Paginator::currentPathResolver(function () use ($container): string {
            if (! Context::has(ServerRequestInterface::class)) {
                return '/';
            }

            return $container->get(RequestInterface::class)->url();
        });

        Paginator::queryStringResolver(function () use ($container): array {
            if (! Context::has(ServerRequestInterface::class)) {
                return [];
            }

            return $container->get(RequestInterface::class)->getQueryParams();
        });

        CursorPaginator::currentCursorResolver(function (string $cursorName = 'cursor') use ($container): ?Cursor {
            if (! Context::has(ServerRequestInterface::class)) {
                return null;
            }

            return Cursor::fromEncoded($container->get(RequestInterface::class)->input($cursorName));
        });
    }
}
