<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeStyleTest extends AbstractBladeTestCase
{
    public function testStylesAreConditionallyCompiledFromArray()
    {
        $string = "<span @style(['font-weight: bold', 'text-decoration: underline', 'color: red' => true, 'margin-top: 10px' => false])></span>";
        $expected = "<span style=\"<?php echo \\Hypervel\\Support\\Arr::toCssStyles(['font-weight: bold', 'text-decoration: underline', 'color: red' => true, 'margin-top: 10px' => false]) ?>\"></span>";

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
