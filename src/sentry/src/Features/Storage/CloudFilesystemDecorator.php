<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

trait CloudFilesystemDecorator
{
    use FilesystemDecorator;

    /**
     * Get the URL for the file at the given path.
     */
    public function url(string $path): string
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }
}
