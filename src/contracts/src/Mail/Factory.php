<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Mail;

interface Factory
{
    /**
     * Get a mailer instance by name.
     */
    public function mailer(?string $name = null): Mailer;
}
