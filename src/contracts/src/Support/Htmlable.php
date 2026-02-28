<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

interface Htmlable
{
    /**
     * Get content as a string of HTML.
     */
    public function toHtml(): string;
}
