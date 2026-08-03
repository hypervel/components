<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Database\Eloquent\Model;

class Post extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var string[]
     */
    protected array $guarded = [];

    /**
     * Return whether the post is published.
     */
    public function getIsPublishedAttribute(): bool
    {
        return true;
    }
}
