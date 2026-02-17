<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

interface MessageProvider
{
    /**
     * Get the messages for the instance.
     */
    public function getMessageBag(): MessageBag;
}
