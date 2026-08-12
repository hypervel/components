<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Elements;

class KeyValueList implements ElementContract
{
    /**
     * Create a new key-value list element.
     *
     * @param array<int|string, string> $items
     */
    public function __construct(public readonly array $items)
    {
    }
}
