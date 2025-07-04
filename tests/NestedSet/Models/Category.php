<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\HasNode;

class Category extends Model
{
    use SoftDeletes;
    use HasNode;

    protected array $fillable = ['name', 'parent_id'];

    public bool $timestamps = false;

    // public static function resetActionsPerformed()
    // {
    //     static::$actionsPerformed = 0;
    // }
}
