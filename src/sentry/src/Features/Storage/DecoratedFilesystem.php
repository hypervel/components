<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Hypervel\Contracts\Filesystem\Filesystem;

/**
 * @internal
 */
interface DecoratedFilesystem
{
    /**
     * Get the filesystem wrapped by this decorator.
     */
    public function getFilesystem(): Filesystem;
}
