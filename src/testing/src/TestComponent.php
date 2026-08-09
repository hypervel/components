<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Testing\Assert as PHPUnit;
use Hypervel\Testing\Constraints\SeeInHtml;
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
     * Assert that the given string or array of strings are contained within the rendered component.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertSee(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        foreach ($values as $value) {
            PHPUnit::assertStringContainsString((string) $value, $this->rendered);
        }

        return $this;
    }

    /**
     * Assert that the given HTML string or array of HTML strings are contained within the rendered component.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertSeeHtml(array|string $value): static
    {
        return $this->assertSee($value, false);
    }

    /**
     * Assert that the given strings are contained in order within the rendered component.
     *
     * @param list<string> $values
     * @return $this
     */
    public function assertSeeInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(e(...), $values) : $values;

        PHPUnit::assertThat($values, new SeeInOrder($this->rendered));

        return $this;
    }

    /**
     * Assert that the given HTML strings are contained in order within the rendered component.
     *
     * @param list<string> $values
     * @return $this
     */
    public function assertSeeHtmlInOrder(array $values): static
    {
        return $this->assertSeeInOrder($values, false);
    }

    /**
     * Assert that the given string or array of strings are contained within the rendered component text.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertSeeText(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        PHPUnit::assertThat($values, new SeeInHtml($this->rendered));

        return $this;
    }

    /**
     * Assert that the given strings are contained in order within the rendered component text.
     *
     * @param list<string> $values
     * @return $this
     */
    public function assertSeeTextInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(e(...), $values) : $values;

        PHPUnit::assertThat($values, new SeeInHtml($this->rendered, true));

        return $this;
    }

    /**
     * Assert that the given string or array of strings are not contained within the rendered component.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertDontSee(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        foreach ($values as $value) {
            PHPUnit::assertStringNotContainsString((string) $value, $this->rendered);
        }

        return $this;
    }

    /**
     * Assert that the given HTML string or array of HTML strings are not contained within the rendered component.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertDontSeeHtml(array|string $value): static
    {
        return $this->assertDontSee($value, false);
    }

    /**
     * Assert that the given string or array of strings are not contained within the rendered component text.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertDontSeeText(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        PHPUnit::assertThat($values, new SeeInHtml($this->rendered, negate: true));

        return $this;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
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
