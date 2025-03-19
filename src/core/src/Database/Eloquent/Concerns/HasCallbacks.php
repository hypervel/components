<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\ModelListener;
use RuntimeException;

trait HasCallbacks
{
    /**
     * Register event callback for the model.
     *
     * @throws RuntimeException
     */
    public static function registerCallback(string $event, callable $callback): void
    {
        ApplicationContext::getContainer()
            ->get(ModelListener::class)
            ->register(new static(), $event, $callback); /* @phpstan-ignore-line */
    }
}
