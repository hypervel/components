<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\PostLikes;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    public bool $timestamps = false;

    /**
     * Get the post's likes.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }
}
