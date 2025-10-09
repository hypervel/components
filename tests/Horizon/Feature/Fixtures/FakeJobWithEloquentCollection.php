<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

class FakeJobWithEloquentCollection
{
    public $collection;

    public function __construct($collection)
    {
        $this->collection = $collection;
    }
}
