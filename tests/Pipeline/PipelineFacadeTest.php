<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pipeline;

use Hypervel\Pipeline\Pipeline;
use Hypervel\Support\Facades\Pipeline as PipelineFacade;
use Hypervel\Testbench\TestCase;

class PipelineFacadeTest extends TestCase
{
    public function testFacadeReturnsFreshInstanceOnEveryAccess()
    {
        $first = PipelineFacade::getFacadeRoot();
        $second = PipelineFacade::getFacadeRoot();

        $this->assertInstanceOf(Pipeline::class, $first);
        $this->assertInstanceOf(Pipeline::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function testFacadeInstanceIsNotContaminatedByPriorUsage()
    {
        PipelineFacade::send('foo')->through([
            function ($value, $next) {
                return $next($value . '_piped');
            },
        ])->thenReturn();

        // Next facade access should be a clean pipeline with no passable or pipes set.
        $result = PipelineFacade::send('bar')->thenReturn();

        $this->assertSame('bar', $result);
    }
}
