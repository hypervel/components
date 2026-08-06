<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Blade;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Component;
use Mockery as m;
use ReflectionClassConstant;
use RuntimeException;

class BladeComponentsTest extends AbstractBladeTestCase
{
    public function testComponentsAreCompiled(): void
    {
        $this->assertSame('<?php $__env->startComponent(\'foo\', ["foo" => "bar"]); ?>', $this->compiler->compileString('@component(\'foo\', ["foo" => "bar"])'));
        $this->assertSame('<?php $__env->startComponent(\'foo\'); ?>', $this->compiler->compileString('@component(\'foo\')'));
    }

    public function testClassComponentsAreCompiled(): void
    {
        $this->assertSame(str_replace("\r\n", "\n", '<?php if (isset($component)) { $__componentOriginal840388efd2c3908da4effc26be795247 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal840388efd2c3908da4effc26be795247 = $attributes; } ?>
<?php $component = Hypervel\Tests\View\Blade\ComponentStub::class::resolve(["foo" => "bar"] + (isset($attributes) && $attributes instanceof Hypervel\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName(\'test\'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>'), $this->compiler->compileString('@component(\'Hypervel\Tests\View\Blade\ComponentStub::class\', \'test\', ["foo" => "bar"])'));
    }

    public function testClassComponentHashesAreClearedAfterSuccessfulCompilation(): void
    {
        $this->compiler->compileString('@component(\'Hypervel\Tests\View\Blade\ComponentStub::class\', \'test\', ["foo" => "bar"])');

        $contextKey = (new ReflectionClassConstant(BladeCompiler::class, 'COMPONENT_HASH_STACK_CONTEXT_KEY'))->getValue();

        $this->assertSame([], CoroutineContext::get($contextKey, []));
    }

    public function testEndComponentsAreCompiled(): void
    {
        $this->compiler->newComponentHash('foo');

        $this->assertSame('<?php echo $__env->renderComponent(); ?>', $this->compiler->compileString('@endcomponent'));
    }

    public function testEndComponentClassesAreCompiled(): void
    {
        $this->compiler->newComponentHash('foo');

        $this->assertSame($this->expectedEndComponentClass(), $this->compiler->compileString('@endcomponentClass'));
    }

    public function testComponentHashesAreClearedAfterCompilationFailure(): void
    {
        $failure = new RuntimeException('failed');
        $caught = null;

        $this->compiler->directive('fail', fn (): never => throw $failure);

        try {
            $this->compiler->compileString("@component('Hypervel\\Tests\\View\\Blade\\ComponentStub::class', 'test') @fail");
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $contextKey = (new ReflectionClassConstant(BladeCompiler::class, 'COMPONENT_HASH_STACK_CONTEXT_KEY'))->getValue();

        $this->assertSame($failure, $caught);
        $this->assertSame([], CoroutineContext::get($contextKey, []));

        $this->compiler->newComponentHash('foo');

        $this->assertSame($this->expectedEndComponentClass(), $this->compiler->compileString('@endcomponentClass'));
    }

    public function testSlotsAreCompiled(): void
    {
        $this->assertSame('<?php $__env->slot(\'foo\', null, ["foo" => "bar"]); ?>', $this->compiler->compileString('@slot(\'foo\', null, ["foo" => "bar"])'));
        $this->assertSame('<?php $__env->slot(\'foo\'); ?>', $this->compiler->compileString('@slot(\'foo\')'));
    }

    public function testEndSlotsAreCompiled(): void
    {
        $this->assertSame('<?php $__env->endSlot(); ?>', $this->compiler->compileString('@endslot'));
    }

    public function testPropsAreExtractedFromParentAttributesCorrectlyForClassComponents(): void
    {
        $component = m::mock(ComponentStub::class);
        $component->shouldReceive('withName', 'test');
        $component->shouldReceive('shouldRender')->andReturn(false);

        Component::resolveComponentsUsing(fn () => $component);

        $template = $this->compiler->compileString('@component(\'Hypervel\Tests\View\Blade\ComponentStub::class\', \'test\', ["foo" => "bar"])');

        ob_start();
        eval(" ?> {$template} <?php endif; ");

        $this->assertSame('', trim((string) ob_get_clean()));
    }

    private function expectedEndComponentClass(): string
    {
        return str_replace("\r\n", "\n", '<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal79aef92e83454121ab6e5f64077e7d8a)): ?>
<?php $attributes = $__attributesOriginal79aef92e83454121ab6e5f64077e7d8a; ?>
<?php unset($__attributesOriginal79aef92e83454121ab6e5f64077e7d8a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal79aef92e83454121ab6e5f64077e7d8a)): ?>
<?php $component = $__componentOriginal79aef92e83454121ab6e5f64077e7d8a; ?>
<?php unset($__componentOriginal79aef92e83454121ab6e5f64077e7d8a); ?>
<?php endif; ?>');
    }
}

class ComponentStub extends Component
{
    public function render(): ViewContract|Htmlable|Closure|string
    {
        return '';
    }
}
