<?php

declare(strict_types=1);

namespace Hypervel\View;

use ErrorException;
use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Support\Reflector;
use Symfony\Component\HttpFoundation\Response;

class ViewException extends ErrorException
{
    /**
     * Report the exception.
     */
    public function report(): ?bool
    {
        $exception = $this->getPrevious();

        if (Reflector::isCallable($reportCallable = [$exception, 'report'])) {
            return Container::getInstance()->call($reportCallable);
        }

        return false;
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): ?Response
    {
        $exception = $this->getPrevious();

        if ($exception && method_exists($exception, 'render')) {
            return $exception->render($request);
        }

        return null;
    }
}
