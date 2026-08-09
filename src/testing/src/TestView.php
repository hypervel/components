<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Closure;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Testing\Assert as PHPUnit;
use Hypervel\Testing\Constraints\SeeInHtml;
use Hypervel\Testing\Constraints\SeeInOrder;
use Hypervel\View\View;
use Stringable;

class TestView implements Stringable
{
    use Macroable;

    /**
     * The original view.
     */
    protected View $view;

    /**
     * The rendered view contents.
     */
    protected string $rendered;

    /**
     * Create a new test view instance.
     */
    public function __construct(View $view)
    {
        $this->view = $view;
        $this->rendered = $view->render();
    }

    /**
     * Assert that the response view has a given piece of bound data.
     *
     * @return $this
     */
    public function assertViewHas(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            return $this->assertViewHasAll($key);
        }

        if (is_null($value)) {
            PHPUnit::assertTrue(Arr::has($this->view->gatherData(), $key));
        } elseif ($value instanceof Closure) {
            PHPUnit::assertTrue($value(Arr::get($this->view->gatherData(), $key)));
        } elseif ($value instanceof Model) {
            $actual = Arr::get($this->view->gatherData(), $key);

            PHPUnit::assertTrue($actual instanceof Model && ($value === $actual || $value->is($actual)));
        } elseif ($value instanceof EloquentCollection) {
            $actual = Arr::get($this->view->gatherData(), $key);

            PHPUnit::assertInstanceOf(EloquentCollection::class, $actual);
            PHPUnit::assertSameSize($value, $actual);

            $value->each(function ($item, $index) use ($actual): void {
                $actualItem = $actual->get($index);

                PHPUnit::assertTrue($actualItem instanceof Model && ($item === $actualItem || $item->is($actualItem)));
            });
        } else {
            PHPUnit::assertEquals($value, Arr::get($this->view->gatherData(), $key));
        }

        return $this;
    }

    /**
     * Assert that the response view has a given list of bound data.
     *
     * @return $this
     */
    public function assertViewHasAll(array $bindings): static
    {
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $this->assertViewHas($value);
            } else {
                $this->assertViewHas($key, $value);
            }
        }

        return $this;
    }

    /**
     * Assert that the response view is missing a piece of bound data.
     *
     * @return $this
     */
    public function assertViewMissing(string $key): static
    {
        PHPUnit::assertFalse(Arr::has($this->view->gatherData(), $key));

        return $this;
    }

    /**
     * Assert that the view's rendered content is empty.
     *
     * @return $this
     */
    public function assertViewEmpty(): static
    {
        PHPUnit::assertEmpty($this->rendered);

        return $this;
    }

    /**
     * Assert that the given string or array of strings are contained within the view.
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
     * Assert that the given HTML string or array of HTML strings are contained within the view.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertSeeHtml(array|string $value): static
    {
        return $this->assertSee($value, false);
    }

    /**
     * Assert that the given strings are contained in order within the view.
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
     * Assert that the given HTML strings are contained in order within the view.
     *
     * @param list<string> $values
     * @return $this
     */
    public function assertSeeHtmlInOrder(array $values): static
    {
        return $this->assertSeeInOrder($values, false);
    }

    /**
     * Assert that the given string or array of strings are contained within the view text.
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
     * Assert that the given strings are contained in order within the view text.
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
     * Assert that the given string or array of strings are not contained within the view.
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
     * Assert that the given HTML string or array of HTML strings are not contained within the view.
     *
     * @param list<string>|string $value
     * @return $this
     */
    public function assertDontSeeHtml(array|string $value): static
    {
        return $this->assertDontSee($value, false);
    }

    /**
     * Assert that the given string or array of strings are not contained within the view text.
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
     * Get the string contents of the rendered view.
     */
    public function __toString(): string
    {
        return $this->rendered;
    }
}
