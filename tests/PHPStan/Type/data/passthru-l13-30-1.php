<?php

namespace PassthruL13301;

use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

function test(): void
{
    // Unlike upstream, the query builder declares the returned rows.
    assertType('Hypervel\Support\Collection<int, object>', User::query()->insertOrIgnoreReturning([]));
}

class Post extends Model
{
}

/** @extends Builder<Post> */
class PassthruBuilder extends Builder
{
    public function test(): void
    {
        assertType('Hypervel\Support\Collection<int, object>', $this->insertOrIgnoreReturning([]));
    }
}
