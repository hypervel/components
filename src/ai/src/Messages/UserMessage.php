<?php

declare(strict_types=1);

namespace Hypervel\Ai\Messages;

use Hypervel\Support\Collection;

class UserMessage extends Message
{
    /**
     * The message's attachments.
     */
    public Collection $attachments;

    /**
     * Create a new text conversation message instance.
     */
    public function __construct(string $content, Collection|array $attachments = [])
    {
        parent::__construct('user', $content);

        $this->attachments = Collection::wrap($attachments);
    }
}
