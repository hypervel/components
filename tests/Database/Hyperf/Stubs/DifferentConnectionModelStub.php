<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

class DifferentConnectionModelStub extends ModelStub
{
    public ?string $connection = 'different_connection';
}
