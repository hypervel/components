<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Hypervel\View\ComponentAttributeBag;

class BladePropsTest extends AbstractBladeTestCase
{
    public function testPropsAreCompiled(): void
    {
        $this->assertSame('<?php $attributes ??= new \Hypervel\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Hypervel\View\ComponentAttributeBag::extractPropNames(([\'one\' => true, \'two\' => \'string\']));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Hypervel\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([\'one\' => true, \'two\' => \'string\']), \'is_string\', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>', $this->compiler->compileString('@props([\'one\' => true, \'two\' => \'string\'])'));
    }

    public function testPropsAreExtractedFromParentAttributesCorrectly(): void
    {
        $test1 = $test2 = $test4 = null;

        $attributes = new ComponentAttributeBag(['test1' => 'value1', 'test2' => 'value2', 'test3' => 'value3']);

        $template = $this->compiler->compileString('@props([\'test1\' => \'default\', \'test2\', \'test4\' => \'default\'])');

        ob_start();
        eval(" ?> {$template} <?php ");
        ob_get_clean();

        $this->assertSame('value1', $test1);
        $this->assertSame('value2', $test2);
        $this->assertFalse(isset($test3));
        $this->assertSame('default', $test4);

        $this->assertNull($attributes->get('test1'));
        $this->assertNull($attributes->get('test2'));
        $this->assertSame('value3', $attributes->get('test3'));
    }

    public function testPropsCleanupDoesNotLeakCompilerHelperVariables(): void
    {
        $attributes = new ComponentAttributeBag([
            'message' => 'Hello',
            'class' => 'font-bold',
        ]);

        $template = $this->compiler->compileString('@props([\'message\' => \'Default\'])');

        ob_start();
        eval(" ?> {$template} <?php ");
        ob_get_clean();

        $definedVariables = get_defined_vars();

        $this->assertSame('Hello', $message);
        $this->assertNull($attributes->get('message'));
        $this->assertSame('font-bold', $attributes->get('class'));
        $this->assertArrayNotHasKey('__defined_vars', $definedVariables);
        $this->assertArrayNotHasKey('__key', $definedVariables);
        $this->assertArrayNotHasKey('__value', $definedVariables);
    }
}
