<?php

declare(strict_types=1);

namespace App;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Concerns\HasUuids;

class UuidModel extends Model
{
    use HasUuids;
}
