<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Model;

class ModelEventObjectStub extends Model
{
    protected array $events = [
        'saving' => ModelSavingEventStub::class,
    ];
}
