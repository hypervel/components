<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Hypervel\Contracts\Filesystem\Cloud;
use Hypervel\ObjectPool\Contracts\InvalidatesPool;

class SentryCloudFilesystem implements Cloud, DecoratedFilesystem, InvalidatesPool
{
    use CloudFilesystemDecorator;

    /**
     * Create a new cloud filesystem decorator.
     */
    public function __construct(Cloud $filesystem, array $defaultData, bool $recordSpans, bool $recordBreadcrumbs)
    {
        $this->filesystem = $filesystem;
        $this->defaultData = $defaultData;
        $this->recordSpans = $recordSpans;
        $this->recordBreadcrumbs = $recordBreadcrumbs;
    }
}
