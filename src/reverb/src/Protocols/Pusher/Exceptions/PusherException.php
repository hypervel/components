<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Exceptions;

use Exception;

abstract class PusherException extends Exception
{
    /**
     * @var int
     */
    protected $code;

    /**
     * @var string
     */
    protected $message;

    /**
     * Get the Pusher formatted error payload.
     */
    public function payload(): array
    {
        return [
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => $this->code,
                'message' => $this->message,
            ]),
        ];
    }
}
