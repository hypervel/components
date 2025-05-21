<?php

declare(strict_types=1);

namespace Hypervel\Validation;

interface DatabasePresenceVerifierInterface extends PresenceVerifierInterface
{
    /**
     * Set the connection to be used.
     */
    public function setConnection(?string $connection): void;
}
