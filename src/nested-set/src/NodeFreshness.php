<?php

declare(strict_types=1);

namespace Hypervel\NestedSet;

use Hypervel\Context\ReplicableContext;
use Hypervel\Database\Eloquent\Model;
use WeakMap;

/**
 * Track structural revisions and the live models hydrated from them.
 *
 * Every structural write must advance this state. Missing an advance would
 * leave already-observed models trusted after their database rows became stale.
 * A partial refresh may observe a model only when the preceding write cannot
 * change the omitted parent or scope identity.
 */
final class NodeFreshness implements ReplicableContext
{
    private int $revision = 0;

    /** @var WeakMap<Model, int> */
    private WeakMap $observations;

    public function __construct()
    {
        $this->observations = new WeakMap;
    }

    /**
     * Advance the structural revision.
     */
    public function advance(): void
    {
        ++$this->revision;
    }

    /**
     * Determine whether the model was observed at the current revision.
     */
    public function isCurrent(Model $model): bool
    {
        return ($this->observations[$model] ?? null) === $this->revision;
    }

    /**
     * Record the model at the current revision.
     */
    public function observe(Model $model): void
    {
        $this->observations[$model] = $this->revision;
    }

    /**
     * Create an independent state without copying model observations.
     */
    public function replicate(): static
    {
        $state = new self;
        $state->revision = $this->revision;

        return $state;
    }
}
