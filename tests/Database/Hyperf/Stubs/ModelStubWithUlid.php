<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Concerns\HasUlids;
use Hyperf\Database\Model\Model;

class ModelStubWithUlid extends Model
{
    use HasUlids;

    protected ?string $table = 'stub';

    protected string $primaryKey = 'id';
}
