<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorCommands\Scale;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;

class ScaleCommandTest extends UnitTestCase
{
    public function testScaleCommandTellsSupervisorToScale(): void
    {
        $supervisor = Double::for(Supervisor::class)->strict();
        $supervisor->expects('scale')->with(3);
        $scale = new Scale;
        $scale->process($supervisor, ['scale' => 3]);
    }
}
