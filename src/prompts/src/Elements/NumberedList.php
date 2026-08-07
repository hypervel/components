<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Elements;

class NumberedList implements ElementContract
{
    /**
     * Create a new numbered list element.
     *
     * @param array<int, string> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $spaced = false,
    ) {
    }
}
