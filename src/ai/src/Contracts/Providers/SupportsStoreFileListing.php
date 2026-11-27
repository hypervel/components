<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Providers;

use Hypervel\Ai\Responses\Data\StoreDocument;
use Hypervel\Support\LazyCollection;

interface SupportsStoreFileListing
{
    /**
     * List the documents in a vector store.
     *
     * @return LazyCollection<int, StoreDocument>
     */
    public function listStoreFiles(string $storeId): LazyCollection;
}
