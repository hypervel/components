<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Stream;

interface FileInterface
{
    /**
     * Get the filename of the file stream.
     */
    public function getFilename(): string;
}
