<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data {
    use Hypervel\Support\Sleep;

    use function Hypervel\Tests\PHPStan\Rules\Coroutine\Data\Clock\sleep as clockSleep;

    class RawSleeps
    {
        public function waits(): void
        {
            sleep(1);
            \usleep(1000);
            Sleep::sleep(1);
            Sleep::usleep(1000);
            clockSleep(1);
        }
    }
}

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data\Clock {
    function sleep(int $seconds): void
    {
    }
}
