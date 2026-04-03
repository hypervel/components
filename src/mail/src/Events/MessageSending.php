<?php

declare(strict_types=1);

namespace Hypervel\Mail\Events;

use Symfony\Component\Mime\Email;

class MessageSending
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Email $message,
        public array $data = [],
    ) {
    }
}
