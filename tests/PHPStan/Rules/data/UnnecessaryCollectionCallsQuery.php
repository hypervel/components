<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;

class UnnecessaryCollectionCallsQuery
{
    /** @phpstan-return mixed */
    public function queryMax()
    {
        return DB::table('users')->get()->max('id');
    }

    public function queryNotEmpty(): bool
    {
        return DB::table('users')->pluck('id')->isNotEmpty();
    }

    /** @return Collection<int, mixed> */
    public function queryPluck(): Collection
    {
        return DB::table('users')->get()->pluck('arbitraryName');
    }
}
