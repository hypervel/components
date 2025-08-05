<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;

class ModelStubWithUuid extends Model
{
    use HasUuids;

    protected ?string $table = 'stub';

    protected string $primaryKey = 'id';
}
