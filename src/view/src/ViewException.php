<?php

declare(strict_types=1);

namespace Hypervel\View;

use ErrorException;
use Hypervel\Container\Container;
use Hypervel\Support\Reflector;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
    public function render(RequestInterface $request): ?ResponseInterface
    {
        $exception = $this->getPrevious();

        if ($exception && method_exists($exception, 'render')) {
            return $exception->render($request);
        }

        return null;
    }
}
