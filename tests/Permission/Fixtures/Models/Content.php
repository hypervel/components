<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;

class Content extends Model
{
    protected array $guarded = [];

    protected ?string $table = 'content';
}
