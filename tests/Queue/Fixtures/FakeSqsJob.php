<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Fixtures;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Queueable;

/**
 * @internal
 * @coversNothing
 */
class FakeSqsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
    }
}
