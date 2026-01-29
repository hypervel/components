<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeFragmentTest extends AbstractBladeTestCase
{
    public function testFragmentStartsAreCompiled()
    {
        $this->assertSame('<?php $__env->startFragment(\'foo\'); ?>', $this->compiler->compileString('@fragment(\'foo\')'));
        $this->assertSame('<?php $__env->startFragment(name(foo)); ?>', $this->compiler->compileString('@fragment(name(foo))'));
    }

    public function testEndFragmentsAreCompiled()
    {
        $this->assertSame('<?php echo $__env->stopFragment(); ?>', $this->compiler->compileString('@endfragment'));
    }
}
