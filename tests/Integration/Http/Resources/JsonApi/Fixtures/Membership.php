<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Database\Eloquent\Relations\Pivot;

class Membership extends Pivot
{
    protected ?string $table = 'team_user';
}
