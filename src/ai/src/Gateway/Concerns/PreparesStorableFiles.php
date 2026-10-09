<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\File;

trait PreparesStorableFiles
{
    /**
     * Prepare file data for upload.
     *
     * @return array{string, string, string}
     */
    protected function prepareStorableFile(StorableFile $file): array
    {
        return [
            $file->content(),
            $file->mimeType() ?? 'application/octet-stream',
            $file->name() ?? 'file',
        ];
    }

    /**
     * Resolve the upload body options and HTTP headers for the given file.
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    protected function resolveProviderOptionsAndHeaders(StorableFile $file, Lab|string $provider): array
    {
        return [
            $file instanceof HasProviderOptions ? $file->providerOptions($provider) : [],
            $file instanceof File ? $file->headers($provider) : [],
        ];
    }
}
