<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Filesystem;

interface Factory
{
    /**
     * Get a filesystem implementation.
     */
    public function disk(?string $name = null): Filesystem;
}
