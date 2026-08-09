<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\ObjectPool\Contracts\InvalidatesPool;

class SentryFilesystem implements DecoratedFilesystem, Filesystem, InvalidatesPool
{
    use FilesystemDecorator;

    /**
     * Create a new filesystem decorator.
     */
    public function __construct(Filesystem $filesystem, array $defaultData, bool $recordSpans, bool $recordBreadcrumbs)
    {
        $this->filesystem = $filesystem;
        $this->defaultData = $defaultData;
        $this->recordSpans = $recordSpans;
        $this->recordBreadcrumbs = $recordBreadcrumbs;
    }
}
