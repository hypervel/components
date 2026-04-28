<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Exceptions;

class ConnectionLimitExceeded extends PusherException
{
    /**
     * @var int
     */
    protected $code = 4004;

    /**
     * @var string
     */
    protected $message = 'Application is over connection quota';
}
