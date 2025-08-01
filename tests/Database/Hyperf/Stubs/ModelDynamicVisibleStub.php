<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Model;

class ModelDynamicVisibleStub extends Model
{
    protected ?string $table = 'stub';

    protected array $guarded = [];

    public function getVisible(): array
    {
        return ['name', 'id'];
    }
}
