<?php

namespace App;

use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Model;

#[Table(name: 'users', key: 'uuid', keyType: 'string')]
class MemberWithCustomKey extends Model
{
}
