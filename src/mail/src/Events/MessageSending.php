<?php

declare(strict_types=1);

namespace Hypervel\Mail\Events;

use Symfony\Component\Mime\Email;

class MessageSending
{
    public bool $shouldSend = true;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Email $message,
        public array $data = []
    ) {
    }

    /**
     * Determine if the message should be sent.
     */
    public function shouldSend(): bool
    {
        return $this->shouldSend;
    }
}
