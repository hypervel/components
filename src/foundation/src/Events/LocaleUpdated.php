<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Events;

class LocaleUpdated
{
    /**
     * Create a new event instance.
     */
    public function __construct(public string $locale)
    {
    }
}
