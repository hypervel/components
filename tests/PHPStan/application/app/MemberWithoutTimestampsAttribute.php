<?php

namespace App;

use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Attributes\WithoutTimestamps;
use Hypervel\Database\Eloquent\Model;

#[Table(name: 'users')]
#[WithoutTimestamps]
class MemberWithoutTimestampsAttribute extends Model
{
}
