<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeOverwriteSectionTest extends AbstractBladeTestCase
{
    public function testOverwriteSectionsAreCompiled()
    {
        $this->assertSame('<?php $__env->stopSection(true); ?>', $this->compiler->compileString('@overwrite'));
    }
}
