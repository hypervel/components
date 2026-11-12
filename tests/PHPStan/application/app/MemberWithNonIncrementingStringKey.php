<?php

namespace App;

use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Model;

#[Table(keyType: 'string', incrementing: false)]
class MemberWithNonIncrementingStringKey extends Model
{
}
