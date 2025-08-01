<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

trait UserTrait
{
    protected function user()
    {
        return $this->belongsTo(User::class, 'id', 'id');
    }
}
