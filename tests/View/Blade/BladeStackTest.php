<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeStackTest extends AbstractBladeTestCase
{
    public function testStackIsCompiled()
    {
        $string = '@stack(\'foo\')';
        $expected = '<?php echo $__env->yieldPushContent(\'foo\'); ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = '@stack(\'foo))\')';
        $expected = '<?php echo $__env->yieldPushContent(\'foo))\'); ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
