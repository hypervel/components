<?php

declare(strict_types=1);

namespace App;

use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Model;

#[Table(name: 'users')]
class MemberWithJsonCasts extends Model
{
    protected array $casts = [
        'payload' => 'array',
        'extra' => 'array',
        'PAYLOAD' => 'array',
        'EXTRA' => 'array',
    ];
}
