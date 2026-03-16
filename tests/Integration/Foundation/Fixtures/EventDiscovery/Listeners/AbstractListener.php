<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners;

use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventOne;

abstract class AbstractListener
{
    abstract public function handle(EventOne $event);
}
