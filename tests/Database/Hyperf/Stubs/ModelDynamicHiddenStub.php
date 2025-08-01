<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Model;

class ModelDynamicHiddenStub extends Model
{
    protected ?string $table = 'stub';

    protected array $guarded = [];

    public function getHidden(): array
    {
        return ['age', 'id'];
    }
}
