<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Contracts\View\Factory as FactoryContract;
use Hypervel\Tests\TestCase;
use Hypervel\View\Compilers\ComponentTagCompiler;
use Hypervel\View\Component;
use Hypervel\View\ComponentAttributeBag;
use Hypervel\View\DynamicComponent;
use Hypervel\View\Factory;
use Hypervel\View\View;
use Mockery as m;
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

    public function testComponentFlushCacheClearsEveryCache(): void
    {
        foreach ([
            'bladeViewCache',
            'constructorParametersCache',
            'methodCache',
            'propertyCache',
            'ignoredParameterNames',
        ] as $property) {
            (new ReflectionProperty(Component::class, $property))->setValue(null, ['cached']);
        }

        Component::flushCache();

        foreach ([
            'bladeViewCache',
            'constructorParametersCache',
            'methodCache',
            'propertyCache',
            'ignoredParameterNames',
        ] as $property) {
            $this->assertSame([], (new ReflectionProperty(Component::class, $property))->getValue());
        }
    }

    public function testComponentFlushStateClearsAllStaticState(): void
    {
        (new ReflectionProperty(Component::class, 'factory'))->setValue(null, m::mock(FactoryContract::class));
        (new ReflectionProperty(Component::class, 'componentsResolver'))->setValue(null, static fn (): null => null);
        (new ReflectionProperty(Component::class, 'bladeViewCache'))->setValue(null, ['cached']);

        Component::flushState();

        $this->assertNull((new ReflectionProperty(Component::class, 'factory'))->getValue());
        $this->assertNull((new ReflectionProperty(Component::class, 'componentsResolver'))->getValue());
        $this->assertSame([], (new ReflectionProperty(Component::class, 'bladeViewCache'))->getValue());
    }
}

class ViewStaticStateComponent
{
}
