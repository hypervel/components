<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

class SupervisorOptionsTest extends IntegrationTestCase
{
    public function testDefaultQueueIsUsedWhenNullIsGiven(): void
    {
        $options = new SupervisorOptions('name', 'redis');
        $this->assertSame('default', $options->queue);
    }

    public function testDefaultQueueIsUsedWhenEmptyStringIsGiven(): void
    {
        $options = new SupervisorOptions('name', 'redis', queue: '');

        $this->assertSame('default', $options->queue);
    }

    public function testZeroQueueIsPreserved(): void
    {
        $options = new SupervisorOptions('name', 'redis', queue: '0');

        $this->assertSame('0', $options->queue);
    }

    public function testOnlySupportedStringStrategiesEnableBalancing(): void
    {
        $this->assertTrue((new SupervisorOptions('name', 'redis', balance: 'simple'))->balancing());
        $this->assertTrue((new SupervisorOptions('name', 'redis', balance: 'auto'))->balancing());
        $this->assertFalse((new SupervisorOptions('name', 'redis', balance: true))->balancing());
        $this->assertFalse((new SupervisorOptions('name', 'redis', balance: false))->balancing());
    }

    public function testJsonOptionIsPassedToWorkers(): void
    {
        $options = new SupervisorOptions('name', 'redis');
        $this->assertStringNotContainsString('--json', $options->toWorkerCommand());

        $options->json = true;
        $this->assertStringContainsString('--json', $options->toWorkerCommand());
        $this->assertStringContainsString('--json', $options->toSupervisorCommand());
    }
}
