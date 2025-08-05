<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Model;

class ModelStubWithUlid extends Model
{
    use HasUlids;

    protected ?string $table = 'stub';

    protected string $primaryKey = 'id';
}
