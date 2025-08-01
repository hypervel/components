<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model\UserExtTrait;

trait CountStringTrait
{
    public function getCountStringAttribute(): string
    {
        return sprintf('%05d', $this->count);
    }
}
