<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    public bool $timestamps = false;

    /**
     * Get the model that owns the comment.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
