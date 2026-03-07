<?php

declare(strict_types=1);

namespace Hypervel\Console\Contracts;

interface NewLineAware
{
    /**
     * Get how many trailing newlines were written.
     */
    public function newLinesWritten(): int;
}
