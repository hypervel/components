<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal\Fixtures;

use Hypervel\Contracts\Signal\SignalHandler;

class SignalHandlerStub implements SignalHandler
{
    public function signals(): array
    {
        return [
            self::WORKER => [SIGTERM],
        ];
    }

    public function handle(int $signal): void
    {
    }
}
