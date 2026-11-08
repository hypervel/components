<?php

namespace App;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;

class PostComment extends Model
{
    /** @return MorphTo<Post, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
