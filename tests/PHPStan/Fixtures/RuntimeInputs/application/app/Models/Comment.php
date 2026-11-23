<?php

declare(strict_types=1);

namespace RuntimeInputs\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    /**
     * Get the commented model.
     *
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
