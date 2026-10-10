<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladeEnvironmentStatementsTest extends AbstractBladeTestCase
{
    public function testEnvStatementsAreCompiled(): void
    {
        $string = "@env('staging')
breeze
@else
boom
@endenv";
        $expected = "<?php if(app()->environment('staging')): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>";
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testEnvStatementsWithMultipleStringParamsAreCompiled(): void
    {
        $string = "@env('staging', 'production')
breeze
@else
boom
@endenv";
        $expected = "<?php if(app()->environment('staging', 'production')): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>";
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testEnvStatementsWithArrayParamAreCompiled(): void
    {
        $string = "@env(['staging', 'production'])
breeze
@else
boom
@endenv";
        $expected = "<?php if(app()->environment(['staging', 'production'])): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>";
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testProductionStatementsAreCompiled(): void
    {
        $string = '@production
breeze
@else
boom
@endproduction';
        $expected = "<?php if(app()->environment('production')): ?>
breeze
<?php else: ?>
boom
<?php endif; ?>";
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
