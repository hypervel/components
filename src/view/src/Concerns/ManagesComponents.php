<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Closure;
use Hypervel\Context\Context;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\View\Contracts\View;
use Hypervel\Support\Arr;
use Hypervel\View\ComponentSlot;

trait ManagesComponents
{
    /**
     * Context key for the components being rendered.
     */
    protected const COMPONENT_STACK_CONTEXT_KEY = 'component_stack';

    /**
     * Context key for the original data passed to the component.
     */
    protected const COMPONENT_DATA_CONTEXT_KEY = 'component_data';

    /**
     * Context key for the component data for the component that is currently being rendered.
     */
    protected const CURRENT_COMPONENT_DATA_CONTEXT_KEY = 'current_component_data';

    /**
     * Context key for the slot contents for the component.
     */
    protected const SLOTS_CONTEXT_KEY = 'slots';

    /**
     * Context key for the names of the slots being rendered.
     */
    protected const SLOT_STACK_CONTEXT_KEY = 'slot_stack';

    /**
     * Start a component rendering process.
     */
    public function startComponent(View|Htmlable|Closure|string $view, array $data = []): void
    {
        if (ob_start()) {
            $this->pushComponentStack($view);

            $this->appendComponentData($data);

            $this->createSlotContext();
        }
    }

    protected function pushComponentStack(View|Htmlable|Closure|string $view): int
    {
        $componentStack = Context::get(static::COMPONENT_STACK_CONTEXT_KEY, []);
        $componentStack[] = $view;
        Context::set(static::COMPONENT_STACK_CONTEXT_KEY, $componentStack);

        return count($componentStack);
    }

    protected function popComponentStack(): View|Htmlable|Closure|string
    {
        $componentStack = Context::get(static::COMPONENT_STACK_CONTEXT_KEY, []);
        $view = array_pop($componentStack);
        Context::set(static::COMPONENT_STACK_CONTEXT_KEY, $componentStack);

        return $view;
    }

    protected function appendComponentData(array $data): void
    {
        $componentData = Context::get(static::COMPONENT_DATA_CONTEXT_KEY, []);
        $componentData[$this->currentComponent()] = $data;
        Context::set(static::COMPONENT_DATA_CONTEXT_KEY, $componentData);
    }

    protected function createSlotContext()
    {
        $slots = Context::get(static::SLOTS_CONTEXT_KEY, []);
        $slots[$this->currentComponent()] = [];
        Context::set(static::SLOTS_CONTEXT_KEY, $slots);
    }

    /**
     * Get the first view that actually exists from the given list, and start a component.
     */
    public function startComponentFirst(array $names, array $data = []): void
    {
        $name = Arr::first($names, function ($item) {
            return $this->exists($item);
        });

        $this->startComponent($name, $data);
    }

    /**
     * Render the current component.
     */
    public function renderComponent(): string
    {
        $view = $this->popComponentStack();

        $previousComponentData = Context::get(static::CURRENT_COMPONENT_DATA_CONTEXT_KEY, []);
        $data = $this->componentData();

        $currentComponentData = array_merge($previousComponentData, $data);
        Context::set(static::CURRENT_COMPONENT_DATA_CONTEXT_KEY, $currentComponentData);

        try {
            $view = value($view, $data);

            if ($view instanceof View) {
                return $view->with($data)->render();
            } elseif ($view instanceof Htmlable) {
                return $view->toHtml();
            } else {
                return $this->make($view, $data)->render();
            }
        } finally {
            Context::set(static::CURRENT_COMPONENT_DATA_CONTEXT_KEY, $previousComponentData);
        }
    }

    /**
     * Get the data for the given component.
     */
    protected function componentData(): array
    {
        $defaultSlot = new ComponentSlot(trim(ob_get_clean()));

        $componentStack = Context::get(static::COMPONENT_STACK_CONTEXT_KEY, []);
        $componentData = Context::get(static::COMPONENT_DATA_CONTEXT_KEY, []);
        $slotsData = Context::get(static::SLOTS_CONTEXT_KEY, []);

        $stackCount = count($componentStack);

        $slots = array_merge([
            '__default' => $defaultSlot,
        ], $slotsData[$stackCount] ?? []);

        return array_merge(
            $componentData[$stackCount] ?? [],
            ['slot' => $defaultSlot],
            $slotsData[$stackCount] ?? [],
            ['__laravel_slots' => $slots]
        );
    }

    /**
     * Get an item from the component data that exists above the current component.
     */
    public function getConsumableComponentData(string $key, mixed $default = null): mixed
    {
        $currentComponentData = Context::get(static::CURRENT_COMPONENT_DATA_CONTEXT_KEY, []);

        if (array_key_exists($key, $currentComponentData)) {
            return $currentComponentData[$key];
        }

        $componentStack = Context::get(static::COMPONENT_STACK_CONTEXT_KEY, []);
        $currentComponent = count($componentStack);

        if ($currentComponent === 0) {
            return value($default);
        }

        $componentData = Context::get(static::COMPONENT_DATA_CONTEXT_KEY, []);

        for ($i = $currentComponent - 1; $i >= 0; $i--) {
            $data = $componentData[$i] ?? [];

            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return value($default);
    }

    /**
     * Start the slot rendering process.
     */
    public function slot(string $name, ?string $content = null, array $attributes = []): void
    {
        if (func_num_args() === 2 || $content !== null) {
            $this->setSlotData($name, $content);
        } elseif (ob_start()) {
            $this->setSlotData($name, '');

            $this->pushSlotStack([$name, $attributes]);
        }
    }

    protected function setSlotData(string $name, null|string|ComponentSlot $content): void
    {
        $currentComponent = $this->currentComponent();

        $slots = Context::get(static::SLOTS_CONTEXT_KEY, []);
        $slots[$currentComponent][$name] = $content;
        Context::set(static::SLOTS_CONTEXT_KEY, $slots);
    }

    protected function pushSlotStack(array $value): void
    {
        $currentComponent = $this->currentComponent();

        $slotStack = Context::get(static::SLOT_STACK_CONTEXT_KEY, []);
        $slotStack[$currentComponent][] = $value;
        Context::set(static::SLOT_STACK_CONTEXT_KEY, $slotStack);
    }

    protected function popSlotStack(): array
    {
        $currentComponent = $this->currentComponent();

        $slotStack = Context::get(static::SLOT_STACK_CONTEXT_KEY, []);
        $value = array_pop($slotStack[$currentComponent]);
        Context::set(static::SLOT_STACK_CONTEXT_KEY, $slotStack);

        return $value;
    }

    /**
     * Save the slot content for rendering.
     */
    public function endSlot(): void
    {
        $currentSlot = $this->popSlotStack();

        [$currentName, $currentAttributes] = $currentSlot;

        $this->setSlotData($currentName, new ComponentSlot(
            trim(ob_get_clean()), $currentAttributes
        ));
    }

    /**
     * Get the index for the current component.
     */
    protected function currentComponent(): int
    {
        $componentStack = Context::get(static::COMPONENT_STACK_CONTEXT_KEY, []);
        return count($componentStack) - 1;
    }

    /**
     * Flush all of the component state.
     */
    protected function flushComponents(): void
    {
        Context::set(static::COMPONENT_STACK_CONTEXT_KEY, []);
        Context::set(static::COMPONENT_DATA_CONTEXT_KEY, []);
        Context::set(static::CURRENT_COMPONENT_DATA_CONTEXT_KEY, []);
    }
}
