<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Closure;
use Hypervel\Contracts\Filesystem\Cloud;

/**
 * @extends ScopedFilesystemProxy<Cloud>
 */
class ScopedCloudFilesystemProxy extends ScopedFilesystemProxy implements Cloud
{
    /**
     * Create a dynamically scoped cloud filesystem.
     *
     * @param (Closure(): Cloud)|Cloud $disk
     * @param Closure(): string $prefixResolver
     */
    public function __construct(
        Cloud|Closure $disk,
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

    /**
     * Resolve the inner cloud filesystem for one public operation.
     *
     * The narrowed return type enforces the Cloud contract for dynamically resolved disks.
     */
    protected function resolveDisk(): Cloud
    {
        return parent::resolveDisk();
    }
}
