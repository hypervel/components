<?php

declare(strict_types=1);

namespace App;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Model;

class UlidModel extends Model
{
    use HasUlids;
}
