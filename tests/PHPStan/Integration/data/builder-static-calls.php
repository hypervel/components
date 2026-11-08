<?php

namespace BuilderStaticCalls;

use App\PostBuilder;
use App\User;
use Hypervel\Database\Eloquent\Builder;

function staticCalls(): void
{
    Builder::macro('reviewMacro', fn () => 1);
    Builder::mixin(new \stdClass);
    User::globalCustomDatabaseQueryMacro();
    PostBuilder::globalCustomDatabaseQueryMacro();
    Builder::flushMacros();
}
