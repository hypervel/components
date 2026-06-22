<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladeUseTest extends AbstractBladeTestCase
{
    public function testUseStatementsAreCompiled(): void
    {
        $expected = 'Foo <?php use \SomeNamespace\SomeClass as Foo; ?> bar';

        $string = "Foo @use('SomeNamespace\\SomeClass', 'Foo') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(SomeNamespace\SomeClass, Foo) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithoutAsAreCompiled(): void
    {
        $expected = 'Foo <?php use \SomeNamespace\SomeClass; ?> bar';

        $string = "Foo @use('SomeNamespace\\SomeClass') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(SomeNamespace\SomeClass) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithBackslashAtBeginningAreCompiled(): void
    {
        $expected = 'Foo <?php use \SomeNamespace\SomeClass; ?> bar';

        $string = "Foo @use('\\SomeNamespace\\SomeClass') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(\SomeNamespace\SomeClass) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithBackslashAtBeginningAndAliasedAreCompiled(): void
    {
        $expected = 'Foo <?php use \SomeNamespace\SomeClass as Foo; ?> bar';

        $string = "Foo @use('\\SomeNamespace\\SomeClass', 'Foo') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(\SomeNamespace\SomeClass, Foo) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithBracesAreCompiledCorrectly(): void
    {
        $expected = 'Foo <?php use \SomeNamespace\{Foo, Bar}; ?> bar';

        $string = "Foo @use('SomeNamespace\\{Foo, Bar}') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(SomeNamespace\{Foo, Bar}) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementWithBracesAndBackslashAreCompiledCorrectly(): void
    {
        $expected = 'Foo <?php use \SomeNamespace\{Foo, Bar}; ?> bar';

        $string = "Foo @use('\\SomeNamespace\\{Foo, Bar}') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(\SomeNamespace\{Foo, Bar}) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithModifiersAreCompiled(): void
    {
        $expected = 'Foo <?php use function \SomeNamespace\SomeFunction as Foo; ?> bar';

        $string = "Foo @use('function SomeNamespace\\SomeFunction', 'Foo') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(function SomeNamespace\SomeFunction, Foo) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithModifiersWithoutAliasAreCompiled(): void
    {
        $expected = 'Foo <?php use const \SomeNamespace\SOME_CONST; ?> bar';

        $string = "Foo @use('const SomeNamespace\\SOME_CONST') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(const SomeNamespace\SOME_CONST) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithModifiersAndBackslashAtBeginningAreCompiled(): void
    {
        $expected = 'Foo <?php use function \SomeNamespace\SomeFunction; ?> bar';

        $string = "Foo @use('function \\SomeNamespace\\SomeFunction') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(function \SomeNamespace\SomeFunction) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithModifiersBackslashAtBeginningAndAliasedAreCompiled(): void
    {
        $expected = 'Foo <?php use const \SomeNamespace\SOME_CONST as Foo; ?> bar';

        $string = "Foo @use('const \\SomeNamespace\\SOME_CONST', 'Foo') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(const \SomeNamespace\SOME_CONST, Foo) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseStatementsWithModifiersWithBracesAreCompiledCorrectly(): void
    {
        $expected = 'Foo <?php use function \SomeNamespace\{Foo, Bar}; ?> bar';

        $string = "Foo @use('function SomeNamespace\\{Foo, Bar}') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(function SomeNamespace\{Foo, Bar}) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testUseFunctionStatementWithBracesAndBackslashAreCompiledCorrectly(): void
    {
        $expected = 'Foo <?php use const \SomeNamespace\{FOO, BAR}; ?> bar';

        $string = "Foo @use('const \\SomeNamespace\\{FOO, BAR}') bar";
        $this->assertEquals($expected, $this->compiler->compileString($string));

        $string = 'Foo @use(const \SomeNamespace\{FOO, BAR}) bar';
        $this->assertEquals($expected, $this->compiler->compileString($string));
    }
}
