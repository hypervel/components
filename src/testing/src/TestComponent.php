<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Support\Traits\Macroable;
use Hypervel\Testing\Assert as PHPUnit;
use Hypervel\Testing\Constraints\SeeInOrder;
use Hypervel\View\Component;
use Hypervel\View\View;
use Stringable;

class TestComponent implements Stringable
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * The original component.
     */
    public Component $component;

    /**
     * The rendered component contents.
     */
    protected string $rendered;

    /**
     * Create a new test component instance.
     */
    public function __construct(Component $component, View $view)
    {
        $this->component = $component;

        $this->rendered = $view->render();
    }

    /**
     * Assert that the given string is contained within the rendered component.
     *
     * @return $this
     */
    public function assertSee(string $value, bool $escape = true): static
    {
        $value = $escape ? e($value) : $value;

        PHPUnit::assertStringContainsString((string) $value, $this->rendered);

        return $this;
    }

    /**
     * Assert that the given strings are contained in order within the rendered component.
     *
     * @return $this
     */
    public function assertSeeInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(e(...), $values) : $values;

        PHPUnit::assertThat($values, new SeeInOrder($this->rendered));

        return $this;
    }

    /**
     * Assert that the given string is contained within the rendered component text.
     *
     * @return $this
     */
    public function assertSeeText(string $value, bool $escape = true): static
    {
        $value = $escape ? e($value) : $value;

        PHPUnit::assertStringContainsString((string) $value, strip_tags($this->rendered));

        return $this;
    }

    /**
     * Assert that the given strings are contained in order within the rendered component text.
     *
     * @return $this
     */
    public function assertSeeTextInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(e(...), $values) : $values;

        PHPUnit::assertThat($values, new SeeInOrder(strip_tags($this->rendered)));

        return $this;
    }

    /**
     * Assert that the given string is not contained within the rendered component.
     *
     * @return $this
     */
    public function assertDontSee(string $value, bool $escape = true): static
    {
        $value = $escape ? e($value) : $value;

        PHPUnit::assertStringNotContainsString((string) $value, $this->rendered);

        return $this;
    }

    /**
     * Assert that the given string is not contained within the rendered component text.
     *
     * @return $this
     */
    public function assertDontSeeText(string $value, bool $escape = true): static
    {
        $value = $escape ? e($value) : $value;

        PHPUnit::assertStringNotContainsString((string) $value, strip_tags($this->rendered));

        return $this;
    }

    /**
     * Get the string contents of the rendered component.
     */
    public function __toString(): string
    {
        return $this->rendered;
    }

    /**
     * Dynamically access properties on the underlying component.
     */
    public function __get(string $attribute): mixed
    {
        return $this->component->{$attribute};
    }

    /**
     * Dynamically call methods on the underlying component.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->component->{$method}(...$parameters);
    }
}
