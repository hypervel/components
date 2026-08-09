<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\ObjectPool\Contracts\InvalidatesPool;

class SentryFilesystemAdapter extends FilesystemAdapter implements DecoratedFilesystem, InvalidatesPool
{
    use FilesystemAdapterDecorator;

    /**
     * Create a new Sentry filesystem adapter.
     *
     * @param array<string, mixed> $defaultData
     */
    public function __construct(
        FilesystemAdapter $filesystem,
        array $defaultData,
        bool $recordSpans,
        bool $recordBreadcrumbs,
    ) {
        parent::__construct($filesystem->getDriver(), $filesystem->getAdapter(), $filesystem->getConfig());

        $this->filesystem = $filesystem;
        $this->defaultData = $defaultData;
        $this->recordSpans = $recordSpans;
        $this->recordBreadcrumbs = $recordBreadcrumbs;
    }
}
