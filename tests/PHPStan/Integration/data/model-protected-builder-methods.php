<?php

namespace ModelProtectedBuilderMethods;

use App\User;
use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;

class Report extends Model
{
    /**
     * @param Builder<self> $query
     */
    #[Scope]
    protected function enforceOrderBy(Builder $query): void
    {
        $query->orderBy('id');
    }
}

#[UseEloquentBuilder(LedgerBuilder::class)]
class Ledger extends Model
{
    /**
     * @param Builder<self> $query
     */
    #[Scope]
    protected function enforceOrderBy(Builder $query): void
    {
        $query->orderBy('id');
    }
}

/**
 * @extends Builder<Ledger>
 */
class LedgerBuilder extends Builder
{
}

function calls(User $user, Report $report): void
{
    User::addUpdatedAtColumn([]);
    $user->addUpdatedAtColumn([]);
    Report::enforceOrderBy()->get();
    $report->enforceOrderBy()->get();

    // A builder that fixes its model reaches the scope too. A generic builder cannot: PHPStan asks extensions
    // about a name its class declares with the builder's model unresolved.
    Ledger::query()->enforceOrderBy()->get();
}
