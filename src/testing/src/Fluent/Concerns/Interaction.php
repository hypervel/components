<?php

declare(strict_types=1);

namespace Hypervel\Testing\Fluent\Concerns;

use Hypervel\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;

trait Interaction
{
    /**
     * The list of interacted properties.
     */
    protected array $interacted = [];

    /**
     * Mark the property as interacted.
     */
    protected function interactsWith(string|int $key): void
    {
        $prop = Str::before((string) $key, '.');

        if (! in_array($prop, $this->interacted, true)) {
            $this->interacted[] = $prop;
        }
    }

    /**
     * Assert that all properties have been interacted with.
     */
    public function interacted(): void
    {
        PHPUnit::assertSame(
            [],
            array_diff(array_keys($this->prop()), $this->interacted),
            $this->path
                ? sprintf('Unexpected properties were found in scope [%s].', $this->path)
                : 'Unexpected properties were found on the root level.'
        );
    }

    /**
     * Disable the interaction check.
     */
    public function etc(): static
    {
        $this->interacted = array_keys($this->prop());

        return $this;
    }

    /**
     * Retrieve a prop within the current scope using "dot" notation.
     */
    abstract protected function prop(?string $key = null): mixed;
}
