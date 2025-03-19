<?php

declare(strict_types=1);

namespace Hypervel\Mail\Mailables;

use Hyperf\Collection\Collection;
use Hyperf\Conditionable\Conditionable;
use Hyperf\Stringable\Str;

class Headers
{
    use Conditionable;

    /**
     * Create a new instance of headers for a message.
     *
     * @param null|string $messageId the message's message ID
     * @param array $references the message IDs that are referenced by the message
     * @param array $text the message's text headers
     */
    public function __construct(
        public ?string $messageId = null,
        public array $references = [],
        public array $text = []
    ) {
    }

    /**
     * Set the message ID.
     */
    public function messageId(string $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    /**
     * Set the message IDs referenced by this message.
     */
    public function references(array $references): static
    {
        $this->references = array_merge($this->references, $references);

        return $this;
    }

    /**
     * Set the headers for this message.
     */
    public function text(array $text): static
    {
        $this->text = array_merge($this->text, $text);

        return $this;
    }

    /**
     * Get the references header as a string.
     */
    public function referencesString(): string
    {
        return Collection::make($this->references)->map(function ($messageId) {
            return Str::finish(Str::start($messageId, '<'), '>');
        })->implode(' ');
    }
}
