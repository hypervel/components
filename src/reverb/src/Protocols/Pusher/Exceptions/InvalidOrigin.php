<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Exceptions;

class InvalidOrigin extends PusherException
{
    /**
     * @var int
     */
    protected $code = 4009;

    /**
     * @var string
     */
    protected $message = 'Origin not allowed';
}
