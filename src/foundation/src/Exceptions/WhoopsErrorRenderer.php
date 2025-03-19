<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions;

use Hyperf\Context\RequestContext;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionRenderer;
use Hypervel\Session\Contracts\Session as SessionContract;
use Throwable;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;
use Whoops\RunInterface;

class WhoopsErrorRenderer implements ExceptionRenderer
{
    public function render(Throwable $throwable): string
    {
        $whoops = new Run();
        $whoops->pushHandler(
            $this->setupHandler(new PrettyPageHandler())
        );
        $whoops->allowQuit(false);

        ob_start();
        $whoops->{RunInterface::EXCEPTION_HANDLER}($throwable);

        return ob_get_clean();
    }

    protected function setupHandler($handler)
    {
        $handler->handleUnconditionally(true);

        if (defined('BASE_PATH')) {
            $handler->setApplicationRootPath(BASE_PATH);
        }

        $request = RequestContext::get();
        $handler->addDataTableCallback('PSR7 Query', [$request, 'getQueryParams']);
        $handler->addDataTableCallback('PSR7 Post', [$request, 'getParsedBody']);
        $handler->addDataTableCallback('PSR7 Server', [$request, 'getServerParams']);
        $handler->addDataTableCallback('PSR7 Cookie', [$request, 'getCookieParams']);
        $handler->addDataTableCallback('PSR7 File', [$request, 'getUploadedFiles']);
        $handler->addDataTableCallback('PSR7 Attribute', [$request, 'getAttributes']);

        $container = ApplicationContext::getContainer();
        if ($container->has(SessionContract::class)) {
            $session = $container->get(SessionContract::class);
            if ($session->isStarted()) {
                $handler->addDataTableCallback('Laravel Session', [$session, 'all']);
            }
        }

        return $handler;
    }
}
