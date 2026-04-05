<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Exceptions;

class RateLimitExceeded extends PusherException
{
    /**
     * @var int
     */
    protected $code = 4301;

    /**
     * @var string
     */
    protected $message = 'Rate limit exceeded';
}
