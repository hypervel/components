<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\HasNode;

class MenuItem extends Model
{
    use HasNode;

    public bool $timestamps = false;

    protected array $fillable = ['menu_id', 'parent_id'];

    protected function getScopeAttributes(): array
    {
        return ['menu_id'];
    }
}
