<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hypervel\Console\OutputStyle;
use Hypervel\Context\ReplicableContext;
use WeakReference;

class MigratorState implements ReplicableContext
{
    public ?string $connection = null;

    public ?OutputStyle $output = null;

    /**
     * Associate invocation state with its owning migrator.
     *
     * @param WeakReference<Migrator> $owner
     */
    public function __construct(public readonly WeakReference $owner)
    {
    }

    /**
     * Inherit the connection and output without sharing subsequent assignments.
     */
    public function replicate(): static
    {
        return clone $this;
    }
}
