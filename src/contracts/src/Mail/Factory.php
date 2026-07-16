<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Mail;

use UnitEnum;

interface Factory
{
    /**
     * Get a mailer instance by name.
     */
    public function mailer(UnitEnum|string|null $name = null): Mailer;
}
