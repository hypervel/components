<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Horizon\IntegrationTestCase;

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
