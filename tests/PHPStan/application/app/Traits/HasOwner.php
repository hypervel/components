<?php

declare(strict_types=1);

namespace App\Traits;

use App\User;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

trait HasOwner
{
    public function owner(): ?User
    {
        return $this->ownerRelation;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ownerRelation(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
