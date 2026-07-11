<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use Hypervel\Contracts\Filesystem\Cloud;

class ScopedCloudFilesystemProxy extends ScopedFilesystemProxy implements Cloud
{
    /**
     * Create a dynamically scoped cloud filesystem.
     *
     * @param Closure(): string $prefixResolver
     */
    public function __construct(
        Cloud $disk,
        Closure $prefixResolver,
        bool $allowRootPassthrough = false,
    ) {
        parent::__construct($disk, $prefixResolver, $allowRootPassthrough);
    }

    /**
     * Get the URL for a scoped file.
     */
    public function url(string $path): string
    {
        $prefix = $this->prefix();

        return $this->call(__FUNCTION__, [$this->applyPrefix($prefix, $path)]);
    }
}
