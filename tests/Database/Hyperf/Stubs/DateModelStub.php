<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

class DateModelStub extends ModelStub
{
    public function getDates(): array
    {
        return ['created_at', 'updated_at'];
    }
}
