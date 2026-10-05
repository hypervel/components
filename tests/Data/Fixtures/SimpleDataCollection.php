<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\DataCollection;

class SimpleDataCollection extends DataCollection
{
    /**
     * Convert the collection to pretty-printed JSON.
     */
    public function toJson(int $options = 0): string
    {
        return parent::toJson(JSON_PRETTY_PRINT);
    }
}
