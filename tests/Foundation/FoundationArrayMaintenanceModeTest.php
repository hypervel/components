<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\ArrayMaintenanceMode;
use Hypervel\Tests\TestCase;

class FoundationArrayMaintenanceModeTest extends TestCase
{
    public function testItDeterminesWhetherMaintenanceModeIsActive(): void
    {
        $manager = new ArrayMaintenanceMode;

        $this->assertFalse($manager->active());

        $manager->activate(['payload']);
        $this->assertTrue($manager->active());
    }

    public function testItRetrievesPayload(): void
    {
        $manager = new ArrayMaintenanceMode;

        $manager->activate(['payload']);
        $this->assertSame(['payload'], $manager->data());
    }

    public function testItStoresPayload(): void
    {
        $manager = new ArrayMaintenanceMode;

        $manager->activate(['payload']);

        $this->assertTrue($manager->active());
        $this->assertSame(['payload'], $manager->data());
    }

    public function testItRemovesPayload(): void
    {
        $manager = new ArrayMaintenanceMode;

        $manager->activate(['payload']);
        $manager->deactivate();

        $this->assertFalse($manager->active());
        $this->assertSame([], $manager->data());
    }
}
