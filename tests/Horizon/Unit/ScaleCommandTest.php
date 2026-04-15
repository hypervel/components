<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorCommands\Scale;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class ScaleCommandTest extends UnitTestCase
{
    public function testScaleCommandTellsSupervisorToScale()
    {
        $supervisor = m::mock(Supervisor::class);
        $supervisor->shouldReceive('scale')->once()->with(3);
        $scale = new Scale;
        $scale->process($supervisor, ['scale' => 3]);
    }
}
