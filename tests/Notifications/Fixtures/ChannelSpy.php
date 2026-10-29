<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Fixtures;

use Throwable;

class ChannelSpy
{
    public array $sent = [];

    public mixed $response = null;

    public ?Throwable $exception = null;

    /**
     * Record the notification, then throw the configured exception or return the configured response.
     */
    public function send(mixed $notifiable, mixed $notification): mixed
    {
        $this->sent[] = [$notifiable, $notification];

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->response;
    }
}
