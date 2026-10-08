<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Generator;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\IncludeableData;
use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Partials\PartialsDefinition;

/**
 * @template TKey of array-key
 * @template TValue of BaseData
 */
trait BaseDataCollectable
{
    /**
     * Get the data class stored by the collection.
     *
     * @return class-string<TValue>
     */
    public function getDataClass(): string
    {
        return $this->dataClass;
    }

    /**
     * Get an iterator for the data items.
     *
     * @return Generator<TKey, TValue>
     */
    public function getIterator(): Generator
    {
        $partials = $this->partialsForItems();

        foreach ($this->itemsForIteration() as $key => $item) {
            if ($partials !== null && $item instanceof IncludeableData) {
                $item->getPartialsDefinition()->addResolved($partials);
            }

            yield $key => $item;
        }
    }

    /**
     * Resolve the collection's active partials for items read from it.
     *
     * As in Spatie, an item read from the collection carries the collection's selections.
     * Reads don't consume the collection's temporary selections; its next transformation does.
     *
     * @return null|array{include: list<PartialDefinition>, exclude: list<PartialDefinition>, only: list<PartialDefinition>, except: list<PartialDefinition>}
     */
    protected function partialsForItems(): ?array
    {
        if (! $this->hasPartialsDefinition()) {
            return null;
        }

        $partials = $this->getPartialsDefinition()->resolve($this);

        return PartialsDefinition::hasResolved($partials) ? $partials : null;
    }

    /**
     * Determine whether this object has partial definitions.
     */
    abstract public function hasPartialsDefinition(): bool;

    /**
     * Get the current partial definitions.
     */
    abstract public function getPartialsDefinition(): PartialsDefinition;

    /**
     * Get the underlying items without transforming them.
     *
     * @return iterable<TKey, TValue>
     */
    abstract protected function itemsForIteration(): iterable;
}
