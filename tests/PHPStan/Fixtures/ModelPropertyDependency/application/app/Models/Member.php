<?php

declare(strict_types=1);

namespace ModelPropertyDependency\Models;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;

#[UseEloquentBuilder(BuilderNames::MEMBER)]
class Member extends BaseModel
{
}
