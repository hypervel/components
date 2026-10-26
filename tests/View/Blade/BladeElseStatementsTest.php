<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladeElseStatementsTest extends AbstractBladeTestCase
{
    public function testElseStatementsAreCompiled(): void
    {
        $string = '@if (name(foo(bar)))
breeze
@else
boom
@endif';
        $expected = '<?php if(name(foo(bar))): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
