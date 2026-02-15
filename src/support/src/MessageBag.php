<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hyperf\Support\MessageBag as HyperfMessageBag;
use Hypervel\Contracts\Support\MessageBag as MessageBagContract;
use Hypervel\Contracts\Support\MessageProvider;

class MessageBag extends HyperfMessageBag implements MessageBagContract, MessageProvider
{
    /**
     * Get the messages for the instance.
     */
    public function getMessageBag(): MessageBagContract
    {
        return $this;
    }
}
