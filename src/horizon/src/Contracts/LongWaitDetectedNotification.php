<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface LongWaitDetectedNotification
{
    /**
     * The unique signature of the notification.
     */
    public function signature(): string;
}
