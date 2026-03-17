<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Closure;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Testing\Assert as PHPUnit;
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
            PHPUnit::assertTrue($value->is(Arr::get($this->view->gatherData(), $key)));
        } elseif ($value instanceof EloquentCollection) {
            $actual = Arr::get($this->view->gatherData(), $key);

            PHPUnit::assertInstanceOf(EloquentCollection::class, $actual);
            PHPUnit::assertSameSize($value, $actual);

            $value->each(fn ($item, $index) => PHPUnit::assertTrue($actual->get($index)->is($item)));
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
     * Assert that the given string is contained within the view.
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
     * Assert that the given strings are contained in order within the view.
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
     * Assert that the given string is contained within the view text.
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
     * Assert that the given strings are contained in order within the view text.
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
     * Assert that the given string is not contained within the view.
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
     * Assert that the given string is not contained within the view text.
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
     * Get the string contents of the rendered view.
     */
    public function __toString(): string
    {
        return $this->rendered;
    }
}
