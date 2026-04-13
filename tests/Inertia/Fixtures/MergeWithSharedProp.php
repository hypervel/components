<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\PropertyContext;
use Hypervel\Inertia\ProvidesInertiaProperty;

class MergeWithSharedProp implements ProvidesInertiaProperty
{
    /**
     * @param array<int, mixed> $items
     */
    public function __construct(protected array $items = [])
    {
    }

    public function toInertiaProperty(PropertyContext $prop): mixed
    {
        return array_merge(Inertia::getShared($prop->key, []), $this->items);
    }
}
