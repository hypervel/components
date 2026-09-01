<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use LogicException;

final readonly class DataMethodMatch
{
    /**
     * Create a new data method match.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function __construct(
        public array $arguments,
        public bool $requiresContainerCall,
    ) {
    }

    /**
     * Replace one matched payload without rebuilding the argument map.
     */
    public function replacePayload(mixed $payload, mixed $replacement): self
    {
        $arguments = $this->arguments;

        foreach ($arguments as $key => $argument) {
            if ($argument !== $payload) {
                continue;
            }

            $arguments[$key] = $replacement;

            return new self($arguments, $this->requiresContainerCall);
        }

        throw new LogicException('The matched payload is missing from the invocation arguments.');
    }
}
