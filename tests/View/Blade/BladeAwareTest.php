<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

class BladeAwareTest extends AbstractBladeTestCase
{
    public function testAwareIsCompiled(): void
    {
        $this->assertSame('<?php foreach (([\'color\' => \'gray\']) as $__key => $__value) {
    $__consumeVariable = is_string($__key) ? $__key : $__value;
    $$__consumeVariable = is_string($__key) ? $__env->getConsumableComponentData($__key, $__value) : $__env->getConsumableComponentData($__value);
} unset($__key, $__value, $__consumeVariable); ?>', $this->compiler->compileString('@aware([\'color\' => \'gray\'])'));
    }

    public function testAwareCleanupDoesNotLeakCompilerHelperVariables(): void
    {
        $__env = new class {
            public function getConsumableComponentData(string $key, mixed $default = null): mixed
            {
                return $key === 'color' ? 'purple' : $default;
            }
        };

        $template = $this->compiler->compileString('@aware([\'color\' => \'gray\'])');

        ob_start();
        eval(" ?> {$template} <?php ");
        ob_get_clean();

        $definedVariables = get_defined_vars();

        $this->assertSame('purple', $color);
        $this->assertArrayNotHasKey('__key', $definedVariables);
        $this->assertArrayNotHasKey('__value', $definedVariables);
        $this->assertArrayNotHasKey('__consumeVariable', $definedVariables);
    }
}
