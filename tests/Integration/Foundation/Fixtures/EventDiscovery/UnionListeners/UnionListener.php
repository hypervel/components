<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\UnionListeners;

use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventOne;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventTwo;

class UnionListener
{
    public function handle(EventOne|EventTwo $event)
    {
    }
}
