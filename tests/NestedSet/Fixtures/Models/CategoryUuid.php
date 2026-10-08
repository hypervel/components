<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;

class CategoryUuid extends Category
{
    use HasUuids;

    protected ?string $table = 'categories';
}
