<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeShowTest extends AbstractBladeTestCase
{
    public function testShowsAreCompiled()
    {
        $this->assertSame('<?php echo $__env->yieldSection(); ?>', $this->compiler->compileString('@show'));
    }
}
