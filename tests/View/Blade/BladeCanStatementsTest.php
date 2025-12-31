<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeCanStatementsTest extends AbstractBladeTestCase
{
    public function testCanStatementsAreCompiled()
    {
        $string = '@can (\'update\', [$post])
breeze
@elsecan(\'delete\', [$post])
sneeze
@endcan';
        $expected = '<?php if (app(\Hypervel\Contracts\Auth\Access\Gate::class)->check(\'update\', [$post])): ?>
breeze
<?php elseif (app(\Hypervel\Contracts\Auth\Access\Gate::class)->check(\'delete\', [$post])): ?>
sneeze
<?php endif; ?>';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
