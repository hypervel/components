<?php

declare(strict_types=1);

namespace App;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $address_id
 * @property string $nullable_address_id
 */
class Address extends Model
{
    protected string $keyType = 'uuid';

    /** @return MorphTo<Model, Address> */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}
