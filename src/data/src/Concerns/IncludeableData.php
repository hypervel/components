<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Data\Support\Partials\ForwardsToPartialsDefinition;
use Hypervel\Data\Support\Partials\PartialsDefinition;

trait IncludeableData
{
    use ForwardsToPartialsDefinition;

    protected ?PartialsDefinition $partialDefinitions = null;

    /**
     * Get the current partial definitions.
     */
    public function getPartialsDefinition(): PartialsDefinition
    {
        if ($this->partialDefinitions !== null) {
            return $this->partialDefinitions;
        }

        $this->partialDefinitions = new PartialsDefinition;
        $this->partialDefinitions->addDefaults('include', $this->includeProperties());
        $this->partialDefinitions->addDefaults('exclude', $this->excludeProperties());
        $this->partialDefinitions->addDefaults('only', $this->onlyProperties());
        $this->partialDefinitions->addDefaults('except', $this->exceptProperties());

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
