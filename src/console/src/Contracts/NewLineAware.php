<?php

declare(strict_types=1);

namespace Hypervel\Console\Contracts;

interface NewLineAware
{
    /**
     * Get how many trailing newlines were written.
     */
    public function newLinesWritten(): int;

    /**
     * Determine whether a newline has already been written.
     *
     * @deprecated use newLinesWritten
     */
    public function newLineWritten(): bool;
}
