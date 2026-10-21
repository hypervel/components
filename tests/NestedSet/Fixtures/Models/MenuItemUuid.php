<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet\Fixtures\Models;

use Hypervel\Database\Eloquent\Concerns\HasUuids;

class MenuItemUuid extends MenuItem
{
    use HasUuids;

    protected ?string $table = 'menu_items';
}
