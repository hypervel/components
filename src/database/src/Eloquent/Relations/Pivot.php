<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\AsPivot;

class Pivot extends Model
{
    use AsPivot;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[WithoutIncrementing] would override a subclass's $incrementing = true)
    public bool $incrementing = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[Unguarded] would replace a $guarded = ['*'] a subclass constructor sets before parent::__construct())
    protected array $guarded = [];
}
