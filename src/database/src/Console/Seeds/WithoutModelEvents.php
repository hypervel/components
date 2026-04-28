<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Seeds;

use Hypervel\Database\Eloquent\Model;

trait WithoutModelEvents
{
    /**
     * Prevent model events from being dispatched by the given callback.
     */
    public function withoutModelEvents(callable $callback): callable
    {
        return fn () => Model::withoutEvents($callback);
    }
}
