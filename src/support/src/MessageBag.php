<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Support\MessageBag as HyperfMessageBag;
use Hypervel\Support\Contracts\MessageBag as ContractsMessageBag;
use Hypervel\Support\Contracts\MessageProvider;

class MessageBag extends HyperfMessageBag implements ContractsMessageBag, MessageProvider
{
    /**
     * Get the messages for the instance.
     */
    public function getMessageBag(): ContractsMessageBag
    {
        return $this;
    }
}
