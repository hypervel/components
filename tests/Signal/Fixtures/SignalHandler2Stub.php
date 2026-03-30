<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal\Fixtures;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Signal\SignalHandlerInterface;

class SignalHandler2Stub implements SignalHandlerInterface
{
    public function listen(): array
    {
        return [
            [self::WORKER, SIGTERM],
        ];
    }

    public function handle(int $signal): void
    {
        CoroutineContext::set('test.signal', $signal);
    }
}
