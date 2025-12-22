<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeStopSectionTest extends AbstractBladeTestCase
{
    public function testStopSectionsAreCompiled()
    {
        $this->assertSame('<?php $__env->stopSection(); ?>', $this->compiler->compileString('@stop'));
    }
}
