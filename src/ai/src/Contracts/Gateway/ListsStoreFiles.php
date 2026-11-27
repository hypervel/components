<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Responses\Data\StoreDocument;
use Hypervel\Support\LazyCollection;

interface ListsStoreFiles
{
    /**
     * List the documents in a vector store.
     *
     * @return LazyCollection<int, StoreDocument>
     */
    public function listFiles(StoreProvider $provider, string $storeId): LazyCollection;
}
