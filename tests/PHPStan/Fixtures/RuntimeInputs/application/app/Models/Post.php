<?php

declare(strict_types=1);

namespace RuntimeInputs\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    /**
     * Get the post's comments.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
