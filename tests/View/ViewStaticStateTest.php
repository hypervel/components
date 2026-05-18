<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\ComponentTagCompiler;
use Hypervel\View\ComponentAttributeBag;
use Hypervel\View\DynamicComponent;
use Hypervel\View\Factory;
use Hypervel\View\View;
use ReflectionProperty;

class ViewStaticStateTest extends TestCase
{
    public function testViewFlushStateClearsMacros(): void
    {
        View::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(View::hasMacro('testingStaticStateProbe'));

        View::flushState();

        $this->assertFalse(View::hasMacro('testingStaticStateProbe'));
    }

    public function testComponentAttributeBagFlushStateClearsMacros(): void
    {
        ComponentAttributeBag::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(ComponentAttributeBag::hasMacro('testingStaticStateProbe'));

        ComponentAttributeBag::flushState();

        $this->assertFalse(ComponentAttributeBag::hasMacro('testingStaticStateProbe'));
    }

    public function testFactoryMacrosCanBeFlushed(): void
    {
        Factory::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(Factory::hasMacro('testingStaticStateProbe'));

        Factory::flushMacros();

        $this->assertFalse(Factory::hasMacro('testingStaticStateProbe'));
    }

    public function testDynamicComponentFlushStateClearsStaticCaches(): void
    {
        $compiler = new ReflectionProperty(DynamicComponent::class, 'compiler');
        $componentClasses = new ReflectionProperty(DynamicComponent::class, 'componentClasses');

        $compiler->setValue(null, new ComponentTagCompiler);
        $componentClasses->setValue(null, ['alert' => ViewStaticStateComponent::class]);

        $this->assertInstanceOf(ComponentTagCompiler::class, $compiler->getValue());
        $this->assertSame(['alert' => ViewStaticStateComponent::class], $componentClasses->getValue());

        DynamicComponent::flushState();

        $this->assertNull($compiler->getValue());
        $this->assertSame([], $componentClasses->getValue());
    }
}

class ViewStaticStateComponent
{
}
