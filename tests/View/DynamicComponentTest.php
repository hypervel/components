<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Tests\TestCase;
use Hypervel\View\ComponentAttributeBag;
use Hypervel\View\DynamicComponent;
use ReflectionMethod;

class DynamicComponentTest extends TestCase
{
    public function testCompileSlotsExcludesDefaultSlot(): void
    {
        $component = new DynamicComponent('alert');

        $method = new ReflectionMethod(DynamicComponent::class, 'compileSlots');
        $result = $method->invoke($component, [
            '__default' => (object) ['attributes' => new ComponentAttributeBag],
            'title' => (object) ['attributes' => new ComponentAttributeBag],
        ]);

        $this->assertStringNotContainsString('__default', $result);
        $this->assertStringContainsString('<x-slot name="title"', $result);
        $this->assertStringContainsString('{{ $title }}', $result);
    }

    public function testCompileSlotsReturnsEmptyStringWhenOnlyDefaultSlotIsPresent(): void
    {
        $component = new DynamicComponent('alert');

        $method = new ReflectionMethod(DynamicComponent::class, 'compileSlots');
        $result = $method->invoke($component, [
            '__default' => (object) ['attributes' => new ComponentAttributeBag],
        ]);

        $this->assertSame('', $result);
    }

    public function testBackedEnumComponentNameIsNormalized(): void
    {
        $component = new DynamicComponent(DynamicComponentName::Alert);

        $this->assertSame('alert', $component->component);
    }
}

enum DynamicComponentName: string
{
    case Alert = 'alert';
}
