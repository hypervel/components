<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Concerns\HasUuids;
use Hyperf\Database\Model\Model;

class ModelStubWithUuid extends Model
{
    use HasUuids;

    protected ?string $table = 'stub';

    protected string $primaryKey = 'id';
}
