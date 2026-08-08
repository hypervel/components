<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Elements;

class BulletedList implements ElementContract
{
    /**
     * Create a new bulleted list element.
     *
     * @param array<int, string> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $spaced = false,
    ) {
    }
}
