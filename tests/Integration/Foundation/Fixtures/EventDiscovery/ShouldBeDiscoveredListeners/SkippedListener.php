<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\ShouldBeDiscoveredListeners;

use Hypervel\Contracts\Events\ShouldBeDiscovered;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventOne;

class SkippedListener implements ShouldBeDiscovered
{
    public static function shouldBeDiscovered(): bool
    {
        return false;
    }

    public function handle(EventOne $event): void
    {
    }
}
