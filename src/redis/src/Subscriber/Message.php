<?php

declare(strict_types=1);

namespace Hypervel\Redis\Subscriber;

class Message
{
    public function __construct(
        public readonly string $channel,
        public readonly string $payload,
        public readonly ?string $pattern = null,
    ) {
    }
}
