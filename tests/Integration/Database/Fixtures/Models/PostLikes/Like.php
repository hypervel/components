<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\PostLikes;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class Like extends Model
{
    public bool $timestamps = false;

    /**
     * Get the liked post.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
