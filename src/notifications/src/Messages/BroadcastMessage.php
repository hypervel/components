<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Messages;

use Hypervel\Bus\Queueable;

class BroadcastMessage
{
    use Queueable;

    /**
     * Create a new message instance.
     *
     * @param array $data the data for the notification
     */
    public function __construct(
        public array $data
    ) {
    }

    /**
     * Set the message data.
     */
    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
