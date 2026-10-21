<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Hypervel\Ai\Models\Conversation;
use Hypervel\Database\Eloquent\Relations\MorphMany;

trait HasConversations
{
    /**
     * Get the conversations for the model.
     *
     * @return MorphMany<Conversation, $this>
     */
    public function conversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'participant');
    }
}
