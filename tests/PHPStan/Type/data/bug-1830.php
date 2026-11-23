<?php

namespace InfiniteRecursion;

use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

/** @mixin User */
abstract class BaseModel extends Model { }

class User extends BaseModel { }


function test(User $user): void
{
    // Hypervel: the implicit key cast types the key as int.
    assertType('int', (int) $user->id);
}
