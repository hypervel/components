<?php

declare(strict_types=1);

namespace Hypervel\Pagination\Listeners;

use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\Paginator;
use Psr\Http\Message\ServerRequestInterface;

class PageResolverListener implements ListenerInterface
{
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
        Paginator::currentPageResolver(function (string $pageName = 'page'): int {
            if (! ApplicationContext::hasContainer()
                || ! interface_exists(RequestInterface::class)
                || ! Context::has(ServerRequestInterface::class)
            ) {
                return 1;
            }

            $container = ApplicationContext::getContainer();
            $page = $container->get(RequestInterface::class)->input($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });

        Paginator::currentPathResolver(function (): string {
            $default = '/';
            if (! ApplicationContext::hasContainer()
                || ! interface_exists(RequestInterface::class)
                || ! Context::has(ServerRequestInterface::class)
            ) {
                return $default;
            }

            $container = ApplicationContext::getContainer();
            return $container->get(RequestInterface::class)->url();
        });

        Paginator::queryStringResolver(function (): array {
            if (! ApplicationContext::hasContainer()
                || ! interface_exists(RequestInterface::class)
                || ! Context::has(ServerRequestInterface::class)
            ) {
                return [];
            }

            $container = ApplicationContext::getContainer();
            return $container->get(RequestInterface::class)->getQueryParams();
        });

        CursorPaginator::currentCursorResolver(function (string $cursorName = 'cursor'): ?Cursor {
            if (! ApplicationContext::hasContainer()
                || ! interface_exists(RequestInterface::class)
                || ! Context::has(ServerRequestInterface::class)
            ) {
                return null;
            }

            $container = ApplicationContext::getContainer();
            return Cursor::fromEncoded($container->get(RequestInterface::class)->input($cursorName));
        });
    }
}
