<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Exceptions;

class InvalidMessageFormat extends PusherException
{
    protected const string CLIENT_MESSAGE = 'Invalid message format';

    /**
     * @var int
     */
    protected $code = 4200;

    /**
     * @var string
     */
    protected $message = self::CLIENT_MESSAGE;

    /**
     * Get the client-facing exception message.
     */
    protected function clientMessage(): string
    {
        return self::CLIENT_MESSAGE;
    }
}
