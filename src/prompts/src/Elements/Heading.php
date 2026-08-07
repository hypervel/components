<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Elements;

class Heading implements ElementContract
{
    /**
     * Create a new heading element.
     */
    public function __construct(public readonly string $text)
    {
    }
}
