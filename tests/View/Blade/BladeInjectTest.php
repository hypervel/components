<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladeInjectTest extends AbstractBladeTestCase
{
    public function testDependenciesInjectedAsStringsAreCompiled(): void
    {
        $string = "Foo @inject('baz', 'SomeNamespace\\SomeClass') bar";
        $expected = "Foo <?php \$baz = app('SomeNamespace\\SomeClass'); ?> bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testDependenciesInjectedAsStringsAreCompiledWhenInjectedWithDoubleQuotes(): void
    {
        $string = 'Foo @inject("baz", "SomeNamespace\SomeClass") bar';
        $expected = 'Foo <?php $baz = app("SomeNamespace\SomeClass"); ?> bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testDependenciesAreCompiled(): void
    {
        $string = "Foo @inject('baz', SomeNamespace\\SomeClass::class) bar";
        $expected = 'Foo <?php $baz = app(SomeNamespace\SomeClass::class); ?> bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testDependenciesAreCompiledWithDoubleQuotes(): void
    {
        $string = 'Foo @inject("baz", SomeNamespace\SomeClass::class) bar';
        $expected = 'Foo <?php $baz = app(SomeNamespace\SomeClass::class); ?> bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
