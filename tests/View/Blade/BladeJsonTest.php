<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladeJsonTest extends AbstractBladeTestCase
{
    public function testStatementIsCompiledWithSafeDefaultEncodingOptions(): void
    {
        $string = 'var foo = @json($var);';
        $expected = 'var foo = <?php echo json_encode($var, 15, 512) ?>;';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testEncodingOptionsCanBeOverwritten(): void
    {
        $string = 'var foo = @json($var, JSON_HEX_TAG);';
        $expected = 'var foo = <?php echo json_encode($var, JSON_HEX_TAG, 512) ?>;';

        $this->assertEquals($expected, $this->compiler->compileString($string));
    }

    public function testInlineArrayCommasPreserveSafeDefaultEncodingOptions(): void
    {
        $string = 'var foo = @json(["name" => $name, "id" => $id]);';
        $expected = 'var foo = <?php echo json_encode(["name" => $name, "id" => $id], 15, 512) ?>;';

        $this->assertSame($expected, $this->compiler->compileString($string));
    }

    public function testInterpolatedStringsPreserveSafeDefaultEncodingOptions(): void
    {
        $string = 'var foo = @json(["name" => "{$prefix}-x", "id" => $id]);';
        $expected = 'var foo = <?php echo json_encode(["name" => "{$prefix}-x", "id" => $id], 15, 512) ?>;';

        $this->assertSame($expected, $this->compiler->compileString($string));
    }

    public function testInlineArrayCommasPreserveExplicitOptionsAndDepth(): void
    {
        $string = 'var foo = @json(["name" => $name, "id" => $id], JSON_HEX_TAG, 256);';
        $expected = 'var foo = <?php echo json_encode(["name" => $name, "id" => $id], JSON_HEX_TAG, 256) ?>;';

        $this->assertSame($expected, $this->compiler->compileString($string));
    }
}
