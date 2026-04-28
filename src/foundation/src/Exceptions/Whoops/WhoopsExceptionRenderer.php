<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Whoops;

use Hypervel\Contracts\Foundation\ExceptionRenderer;
use Throwable;
use Whoops\Handler\Handler;
use Whoops\Run as Whoops;

class WhoopsExceptionRenderer implements ExceptionRenderer
{
    /**
     * Render the given exception as HTML.
     */
    public function render(Throwable $throwable): string
    {
        return tap(new Whoops, function (Whoops $whoops) {
            $whoops->appendHandler($this->whoopsHandler());

            $whoops->writeToOutput(false);

            $whoops->allowQuit(false);
        })->handleException($throwable);
    }

    /**
     * Get the Whoops handler for the application.
     */
    protected function whoopsHandler(): Handler
    {
        return (new WhoopsHandler)->forDebug();
    }
}
