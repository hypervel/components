<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions;

use Hypervel\Support\Facades\View;

class RegisterErrorViewPaths
{
    /**
     * Register the error view paths.
     */
    public function __invoke(): void
    {
        if (! View::getFacadeRoot()) {
            return;
        }

        View::replaceNamespace(
            'errors',
            config()->collection('view.paths')
                ->map(fn ($path) => "{$path}/errors")
                ->push(__DIR__ . '/views')
                ->all()
        );
    }
}
