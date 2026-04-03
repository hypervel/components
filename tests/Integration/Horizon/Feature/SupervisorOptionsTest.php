<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class SupervisorOptionsTest extends IntegrationTestCase
{
    public function testDefaultQueueIsUsedWhenNullIsGiven()
    {
        $options = new SupervisorOptions('name', 'redis');
        $this->assertSame('default', $options->queue);
    }
}
