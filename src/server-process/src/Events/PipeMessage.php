<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Events;

class PipeMessage
{
    public function __construct(
        public readonly mixed $data,
    ) {
    }
}
