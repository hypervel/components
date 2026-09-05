<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Data\Support\Partials\ForwardsToPartialsDefinition;
use Hypervel\Data\Support\Partials\PartialsDefinition;

trait IncludeableData
{
    use ForwardsToPartialsDefinition;

    /**
     * Null before defaults are inspected, false when they are empty, or the mutable definition store.
     */
    protected PartialsDefinition|false|null $partialDefinitions = null;

    /**
     * Determine whether this object has partial definitions.
     *
     * @phpstan-impure
     */
    public function hasPartialsDefinition(): bool
    {
        if ($this->partialDefinitions instanceof PartialsDefinition) {
            return ! $this->partialDefinitions->isEmpty();
        }

        if ($this->partialDefinitions === false) {
            return false;
        }

        $includes = $this->includeProperties();
        $excludes = $this->excludeProperties();
        $only = $this->onlyProperties();
        $except = $this->exceptProperties();

        if ($includes === [] && $excludes === [] && $only === [] && $except === []) {
            $this->partialDefinitions = false;

            return false;
        }

        $this->partialDefinitions = new PartialsDefinition;
        $this->partialDefinitions->addDefaults('include', $includes);
        $this->partialDefinitions->addDefaults('exclude', $excludes);
        $this->partialDefinitions->addDefaults('only', $only);
        $this->partialDefinitions->addDefaults('except', $except);

        return true;
    }

    /**
     * Get the current partial definitions.
     */
    public function getPartialsDefinition(): PartialsDefinition
    {
        if ($this->partialDefinitions instanceof PartialsDefinition) {
            return $this->partialDefinitions;
        }

        if ($this->partialDefinitions === null) {
            // Initialize class-owned defaults before creating an empty store for explicit writes.
            $this->hasPartialsDefinition();

            if ($this->partialDefinitions instanceof PartialsDefinition) {
                return $this->partialDefinitions;
            }
        }

        $this->partialDefinitions = new PartialsDefinition;

        return $this->partialDefinitions;
    }

    /**
     * Get class-owned permanent include definitions.
     */
    protected function includeProperties(): array
    {
        return [];
    }

    /**
     * Get class-owned permanent exclude definitions.
     */
    protected function excludeProperties(): array
    {
        return [];
    }

    /**
     * Get class-owned permanent only definitions.
     */
    protected function onlyProperties(): array
    {
        return [];
    }

    /**
     * Get class-owned permanent except definitions.
     */
    protected function exceptProperties(): array
    {
        return [];
    }
}
