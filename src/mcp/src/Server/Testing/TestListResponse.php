<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Testing;

use Hypervel\Container\Container;
use Hypervel\Mcp\Server\Primitive;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use PHPUnit\Framework\Assert;

class TestListResponse
{
    use Conditionable;
    use Macroable;

    /**
     * Create a test list response.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        protected array $items,
    ) {
    }

    /**
     * Assert that the given primitives are registered.
     *
     * @param array<class-string<Primitive>>|class-string<Primitive> $classes
     */
    public function assertRegistered(array|string $classes): static
    {
        $itemNames = Arr::pluck($this->items, 'name');
        $classes = is_array($classes) ? $classes : [$classes];

        foreach ($classes as $class) {
            $name = Container::getInstance()->make($class)->name();

            Assert::assertContains($name, $itemNames, "The expected class [{$class}] was not found in the response content.");
        }

        // @phpstan-ignore-next-line
        Assert::assertTrue(true);

        return $this;
    }

    /**
     * Assert that the given primitives are not registered.
     *
     * @param array<class-string<Primitive>>|class-string<Primitive> $classes
     */
    public function assertNotRegistered(array|string $classes): static
    {
        $itemNames = Arr::pluck($this->items, 'name');
        $classes = is_array($classes) ? $classes : [$classes];

        foreach ($classes as $class) {
            $name = Container::getInstance()->make($class)->name();

            Assert::assertNotContains($name, $itemNames, "The expected class [{$class}] was found in the response content.");
        }

        // @phpstan-ignore-next-line
        Assert::assertTrue(true);

        return $this;
    }

    /**
     * Dump the items and end execution.
     */
    public function dd(): void
    {
        dd($this->items);
    }

    /**
     * Dump the items.
     */
    public function dump(): static
    {
        dump($this->items);

        return $this;
    }
}
