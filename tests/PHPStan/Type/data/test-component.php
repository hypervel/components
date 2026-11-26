<?php

namespace TestComponentType;

use Hypervel\Foundation\Testing\Concerns\InteractsWithViews;
use Hypervel\Testing\TestComponent;
use Hypervel\View\Component;

use function PHPStan\Testing\assertType;

class Alert extends Component
{
    public string $type = 'info';

    public function isDismissible(): bool
    {
        return true;
    }

    public function render(): string
    {
        return '';
    }
}

class AlertTest
{
    use InteractsWithViews;

    public function test(): void
    {
        $component = $this->component(Alert::class);

        assertType('Hypervel\Testing\TestComponent<TestComponentType\Alert>', $component);
        assertType('TestComponentType\Alert', $component->component);

        // Calls and property reads are forwarded to the component.
        assertType('bool', $component->isDismissible());
        assertType('string', $component->type);
        assertType('*ERROR*', $component->doesNotExist());

        // Macros take precedence.
        assertType('Hypervel\Testing\TestComponent<TestComponentType\Alert>', $component->testComponentMacro());
    }
}

function test(TestComponent $component): void
{
    assertType('bool', $component->shouldRender());
    assertType('*ERROR*', $component->isDismissible());
    assertType('Hypervel\Testing\TestComponent', $component->testComponentMacro());
}
