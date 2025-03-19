<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\ObjectPool\PoolProxy;

class NotificationPoolProxy extends PoolProxy
{
    /**
     * Send the given notification..
     */
    public function send(mixed $notifiable, Notification $notification)
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }
}
