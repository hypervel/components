<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;

#[UseFactory(TeamFactory::class)]
class Team extends Model
{
    use HasFactory;

    public bool $timestamps = false;

    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps()
            ->using(Membership::class)
            ->as('membership');
    }
}
