<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

/**
 * @internal
 * @coversNothing
 */
class BladeInjectTest extends AbstractBladeTestCase
{
    public function testDependenciesInjectedAsStringsAreCompiled()
    {
        $string = "Foo @inject('baz', 'SomeNamespace\\SomeClass') bar";
        $expected = "Foo <?php \$baz = app('SomeNamespace\\SomeClass'); ?> bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testDependenciesInjectedAsStringsAreCompiledWhenInjectedWithDoubleQuotes()
    {
        $string = 'Foo @inject("baz", "SomeNamespace\SomeClass") bar';
        $expected = 'Foo <?php $baz = app("SomeNamespace\SomeClass"); ?> bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testDependenciesAreCompiled()
    {
        $string = "Foo @inject('baz', SomeNamespace\\SomeClass::class) bar";
        $expected = 'Foo <?php $baz = app(SomeNamespace\SomeClass::class); ?> bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testDependenciesAreCompiledWithDoubleQuotes()
    {
        $string = 'Foo @inject("baz", SomeNamespace\SomeClass::class) bar';
        $expected = 'Foo <?php $baz = app(SomeNamespace\SomeClass::class); ?> bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
