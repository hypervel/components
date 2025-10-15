<?php

namespace Hypervel\Tests\View\Blade;

class BladeClassTest extends AbstractBladeTestCase
{
    public function testClassesAreConditionallyCompiledFromArray()
    {
        $string = "<span @class(['font-bold', 'mt-4', 'ml-2' => true, 'mr-2' => false])></span>";
        $expected = "<span class=\"<?php echo \Hypervel\Support\Arr::toCssClasses(['font-bold', 'mt-4', 'ml-2' => true, 'mr-2' => false]); ?>\"></span>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
