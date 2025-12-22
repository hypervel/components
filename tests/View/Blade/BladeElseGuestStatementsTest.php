<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeElseGuestStatementsTest extends AbstractBladeTestCase
{
    public function testIfStatementsAreCompiled()
    {
        $string = '@guest("api")
breeze
@elseguest("standard")
wheeze
@endguest';
        $expected = '<?php if(auth()->guard("api")->guest()): ?>
breeze
<?php elseif(auth()->guard("standard")->guest()): ?>
wheeze
<?php endif; ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
