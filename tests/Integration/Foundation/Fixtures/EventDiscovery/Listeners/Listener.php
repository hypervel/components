<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners;

use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventOne;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventTwo;

class Listener
{
    public function handle(EventOne $event)
    {
    }

    public function handleEventOne(EventOne $event)
    {
    }

    public function handleEventTwo(EventTwo $event)
    {
    }
}
