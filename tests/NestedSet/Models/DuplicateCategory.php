<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\HasNode;

class DuplicateCategory extends Model
{
    use HasNode;

    protected ?string $table = 'categories';

    protected array $fillable = ['name'];

    public bool $timestamps = false;
}
