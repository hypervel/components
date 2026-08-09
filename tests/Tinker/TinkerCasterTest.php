<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Error;
use Hypervel\Foundation\Application;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Hypervel\Tinker\TinkerCaster;
use Mockery as m;
use Symfony\Component\VarDumper\Caster\Caster;

class TinkerCasterTest extends TestCase
{
    public function testCanCastCollection(): void
    {
        $result = TinkerCaster::castCollection(new Collection(['foo', 'bar']));

        $this->assertSame([['foo', 'bar']], array_values($result));
    }

    public function testApplicationPropertyErrorsDoNotSuppressLaterValues(): void
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('configurationIsCached')->once()->andThrow(new Error('Unavailable'));
        $application->shouldReceive('version')->once()->andReturn('1.0.0');

        $result = TinkerCaster::castApplication($application);

        $this->assertSame('1.0.0', $result[Caster::PREFIX_VIRTUAL . 'version']);
    }
}
