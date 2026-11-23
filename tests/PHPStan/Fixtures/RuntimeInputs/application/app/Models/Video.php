<?php

declare(strict_types=1);

namespace RuntimeInputs\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;

class Video extends Model
{
    /**
     * Get the comments featured with the video.
     *
     * @return BelongsToMany<Comment, $this>
     */
    public function comments(): BelongsToMany
    {
        return $this->belongsToMany(Comment::class);
    }
}
