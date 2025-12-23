<?php

declare(strict_types=1);

namespace Hypervel\Support\Contracts;

use Hyperf\Contract\MessageProvider as HyperfMessageProvider;
use Hypervel\Support\Contracts\MessageBag;

interface MessageProvider extends HyperfMessageProvider
{
    /**
     * Get the messages for the instance.
     */
    public function getMessageBag(): MessageBag;
}
