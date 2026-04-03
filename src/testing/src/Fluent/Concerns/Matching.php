<?php

declare(strict_types=1);

namespace Hypervel\Testing\Fluent\Concerns;

use Closure;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

use function Hypervel\Support\enum_value;

trait Matching
{
    /**
     * Assert that the property matches the expected value.
     */
    public function where(string $key, mixed $expected): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        if ($expected instanceof Closure) {
            PHPUnit::assertTrue(
                $expected(is_array($actual) ? new Collection($actual) : $actual),
                sprintf('Property [%s] was marked as invalid using a closure.', $this->dotPath($key))
            );

            return $this;
        }

        $expected = $expected instanceof Arrayable
            ? $expected->toArray()
            : enum_value($expected);

        $this->ensureSorted($expected);
        $this->ensureSorted($actual);

        PHPUnit::assertSame(
            $expected,
            $actual,
            sprintf('Property [%s] does not match the expected value.', $this->dotPath($key))
        );

        return $this;
    }

    /**
     * Assert that the property does not match the expected value.
     */
    public function whereNot(string $key, mixed $expected): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        if ($expected instanceof Closure) {
            PHPUnit::assertFalse(
                $expected(is_array($actual) ? new Collection($actual) : $actual),
                sprintf('Property [%s] was marked as invalid using a closure.', $this->dotPath($key))
            );

            return $this;
        }

        $expected = $expected instanceof Arrayable
            ? $expected->toArray()
            : enum_value($expected);

        $this->ensureSorted($expected);
        $this->ensureSorted($actual);

        PHPUnit::assertNotSame(
            $expected,
            $actual,
            sprintf(
                'Property [%s] contains a value that should be missing: [%s, %s]',
                $this->dotPath($key),
                $key,
                $expected
            )
        );

        return $this;
    }

    /**
     * Assert that the property is null.
     */
    public function whereNull(string $key): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        PHPUnit::assertNull(
            $actual,
            sprintf(
                'Property [%s] should be null.',
                $this->dotPath($key),
            )
        );

        return $this;
    }

    /**
     * Assert that the property is not null.
     */
    public function whereNotNull(string $key): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        PHPUnit::assertNotNull(
            $actual,
            sprintf(
                'Property [%s] should not be null.',
                $this->dotPath($key),
            )
        );

        return $this;
    }

    /**
     * Assert that all properties match their expected values.
     */
    public function whereAll(array $bindings): static
    {
        foreach ($bindings as $key => $value) {
            $this->where($key, $value);
        }

        return $this;
    }

    /**
     * Assert that the property is of the expected type.
     */
    public function whereType(string $key, array|string $expected): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        if (! is_array($expected)) {
            $expected = explode('|', $expected);
        }

        PHPUnit::assertContains(
            strtolower(gettype($actual)),
            $expected,
            sprintf('Property [%s] is not of expected type [%s].', $this->dotPath($key), implode('|', $expected))
        );

        return $this;
    }

    /**
     * Assert that all properties are of their expected types.
     */
    public function whereAllType(array $bindings): static
    {
        foreach ($bindings as $key => $value) {
            $this->whereType($key, $value);
        }

        return $this;
    }

    /**
     * Assert that the property contains the expected values.
     */
    public function whereContains(string $key, mixed $expected): static
    {
        $actual = new Collection(
            $this->prop($key) ?? $this->prop()
        );

        $missing = (new Collection($expected))
            ->map(fn ($search) => enum_value($search))
            ->reject(function ($search) use ($key, $actual) {
                if ($actual->containsStrict($key, $search)) {
                    return true;
                }

                return $actual->containsStrict($search);
            });

        if ($missing->whereInstanceOf('Closure')->isNotEmpty()) {
            PHPUnit::assertEmpty(
                $missing->toArray(),
                sprintf(
                    'Property [%s] does not contain a value that passes the truth test within the given closure.',
                    $key,
                )
            );
        } else {
            PHPUnit::assertEmpty(
                $missing->toArray(),
                sprintf(
                    'Property [%s] does not contain [%s].',
                    $key,
                    implode(', ', array_values($missing->toArray()))
                )
            );
        }

        return $this;
    }

    /**
     * Ensure that all properties are sorted the same way, recursively.
     */
    protected function ensureSorted(mixed &$value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as &$arg) {
            $this->ensureSorted($arg);
        }

        ksort($value);
    }

    /**
     * Compose the absolute "dot" path to the given key.
     */
    abstract protected function dotPath(string $key = ''): string;

    /**
     * Ensure that the given prop exists.
     */
    abstract public function has(string|int $key, int|Closure|null $value = null, ?Closure $scope = null): static;

    /**
     * Retrieve a prop within the current scope using "dot" notation.
     */
    abstract protected function prop(?string $key = null): mixed;
}
