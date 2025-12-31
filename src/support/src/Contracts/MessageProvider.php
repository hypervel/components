<?php

declare(strict_types=1);

namespace Hypervel\Support\Contracts;

use Hyperf\Contract\MessageProvider as HyperfMessageProvider;

interface MessageProvider extends HyperfMessageProvider
{
    /**
     * Get the messages for the instance.
     */
    public function getMessageBag(): MessageBag;
}
