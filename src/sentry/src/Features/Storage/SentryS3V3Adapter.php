<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Hypervel\Filesystem\AwsS3V3Adapter;
use Hypervel\ObjectPool\Contracts\InvalidatesPool;

class SentryS3V3Adapter extends AwsS3V3Adapter implements DecoratedFilesystem, InvalidatesPool
{
    use FilesystemAdapterDecorator;

    /**
     * Create a new Sentry S3 filesystem adapter.
     *
     * @param array<string, mixed> $defaultData
     */
    public function __construct(
        AwsS3V3Adapter $filesystem,
        array $defaultData,
        bool $recordSpans,
        bool $recordBreadcrumbs,
    ) {
        parent::__construct($filesystem->getDriver(), $filesystem->getAdapter(), $filesystem->getConfig(), $filesystem->getClient());

        $this->filesystem = $filesystem;
        $this->defaultData = $defaultData;
        $this->recordSpans = $recordSpans;
        $this->recordBreadcrumbs = $recordBreadcrumbs;
    }
}
