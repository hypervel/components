<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use ArrayAccess;
use Closure;
use Countable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Testing\Assert as PHPUnit;
use JsonSerializable;

use function data_get;
use function Hypervel\Support\enum_value;

class AssertableJsonString implements ArrayAccess, Countable
{
    /**
     * The original encoded json.
     */
    public Jsonable|JsonSerializable|array|string $json;

    /**
     * The decoded json contents.
     */
    protected ?array $decoded = null;

    /**
     * Create a new assertable JSON string instance.
     */
    public function __construct(Jsonable|JsonSerializable|array|string $jsonable)
    {
        $this->json = $jsonable;

        if ($jsonable instanceof JsonSerializable) {
            $this->decoded = $jsonable->jsonSerialize();
        } elseif ($jsonable instanceof Jsonable) {
            $this->decoded = json_decode($jsonable->toJson(), true);
        } elseif (is_array($jsonable)) {
            $this->decoded = $jsonable;
        } else {
            $this->decoded = json_decode($jsonable, true);
        }
    }

    /**
     * Validate and return the decoded response JSON.
     */
    public function json(?string $key = null): mixed
    {
        return data_get($this->decoded, $key);
    }

    /**
     * Assert that the response JSON has the expected count of items at the given key.
     */
    public function assertCount(int $count, ?string $key = null): static
    {
        if (! is_null($key)) {
            PHPUnit::assertCount(
                $count,
                data_get($this->decoded, $key),
                "Failed to assert that the response count matched the expected {$count}"
            );

            return $this;
        }

        PHPUnit::assertCount(
            $count,
            $this->decoded,
            "Failed to assert that the response count matched the expected {$count}"
        );

        return $this;
    }

    /**
     * Assert that the response has the exact given JSON.
     */
    public function assertExact(array $data): static
    {
        $actual = $this->reorderAssocKeys((array) $this->decoded);

        $expected = $this->reorderAssocKeys($data);

        PHPUnit::assertEquals(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $this;
    }

    /**
     * Assert that the response has the similar JSON as given.
     */
    public function assertSimilar(array $data): static
    {
        $actual = json_encode(
            Arr::sortRecursive((array) $this->decoded),
            JSON_UNESCAPED_UNICODE
        );

        PHPUnit::assertEquals(json_encode(Arr::sortRecursive($data), JSON_UNESCAPED_UNICODE), $actual);

        return $this;
    }

    /**
     * Assert that the response contains the given JSON fragment.
     */
    public function assertFragment(array $data): static
    {
        $actual = json_encode(
            Arr::sortRecursive((array) $this->decoded),
            JSON_UNESCAPED_UNICODE
        );

        foreach (Arr::sortRecursive($data) as $key => $value) {
            $expected = $this->jsonSearchStrings($key, $value);

            PHPUnit::assertTrue(
                Str::contains($actual, $expected),
                'Unable to find JSON fragment: ' . PHP_EOL . PHP_EOL
                . '[' . json_encode([$key => $value], JSON_UNESCAPED_UNICODE) . ']' . PHP_EOL . PHP_EOL
                . 'within' . PHP_EOL . PHP_EOL
                . "[{$actual}]."
            );
        }

        return $this;
    }

    /**
     * Assert that the response does not contain the given JSON fragment.
     */
    public function assertMissing(array $data, bool $exact = false): static
    {
        if ($exact) {
            return $this->assertMissingExact($data);
        }

        $actual = json_encode(
            Arr::sortRecursive((array) $this->decoded),
            JSON_UNESCAPED_UNICODE
        );

        foreach (Arr::sortRecursive($data) as $key => $value) {
            $unexpected = $this->jsonSearchStrings($key, $value);

            PHPUnit::assertFalse(
                Str::contains($actual, $unexpected),
                'Found unexpected JSON fragment: ' . PHP_EOL . PHP_EOL
                . '[' . json_encode([$key => $value], JSON_UNESCAPED_UNICODE) . ']' . PHP_EOL . PHP_EOL
                . 'within' . PHP_EOL . PHP_EOL
                . "[{$actual}]."
            );
        }

        return $this;
    }

    /**
     * Assert that the response does not contain the exact JSON fragment.
     */
    public function assertMissingExact(array $data): static
    {
        $actual = json_encode(
            Arr::sortRecursive((array) $this->decoded),
            JSON_UNESCAPED_UNICODE
        );

        foreach (Arr::sortRecursive($data) as $key => $value) {
            $unexpected = $this->jsonSearchStrings($key, $value);

            if (! Str::contains($actual, $unexpected)) {
                return $this;
            }
        }

        PHPUnit::fail(
            'Found unexpected JSON fragment: ' . PHP_EOL . PHP_EOL
            . '[' . json_encode($data, JSON_UNESCAPED_UNICODE) . ']' . PHP_EOL . PHP_EOL
            . 'within' . PHP_EOL . PHP_EOL
            . "[{$actual}]."
        );
    }

    /**
     * Assert that the response does not contain the given path.
     */
    public function assertMissingPath(string $path): static
    {
        PHPUnit::assertFalse(Arr::has($this->json(), $path));

        return $this;
    }

    /**
     * Assert that the expected value and type exists at the given path in the response.
     */
    public function assertPath(string $path, mixed $expect): static
    {
        if ($expect instanceof Closure) {
            PHPUnit::assertTrue($expect($this->json($path)));
        } else {
            PHPUnit::assertSame(enum_value($expect), $this->json($path));
        }

        return $this;
    }

    /**
     * Assert that the given path in the response contains all of the expected values without looking at the order.
     */
    public function assertPathCanonicalizing(string $path, array $expect): static
    {
        PHPUnit::assertEqualsCanonicalizing($expect, $this->json($path));

        return $this;
    }

    /**
     * Assert that the response has a given JSON structure.
     */
    public function assertStructure(?array $structure = null, ?array $responseData = null, bool $exact = false): static
    {
        if (is_null($structure)) {
            return $this->assertSimilar($this->decoded);
        }

        if (! is_null($responseData)) {
            return (new static($responseData))->assertStructure($structure, null, $exact);
        }

        if ($exact) {
            PHPUnit::assertIsArray($this->decoded);

            $keys = (new Collection($structure))->map(fn ($value, $key) => is_array($value) ? $key : $value)->values();

            if ($keys->all() !== ['*']) {
                PHPUnit::assertEquals($keys->sort()->values()->all(), (new Collection($this->decoded))->keys()->sort()->values()->all());
            }
        }

        foreach ($structure as $key => $value) {
            if (is_array($value) && $key === '*') {
                PHPUnit::assertIsArray($this->decoded);

                foreach ($this->decoded as $responseDataItem) {
                    $this->assertStructure($structure['*'], $responseDataItem, $exact);
                }
            } elseif (is_array($value)) {
                PHPUnit::assertArrayHasKey($key, $this->decoded);

                $this->assertStructure($structure[$key], $this->decoded[$key], $exact);
            } else {
                PHPUnit::assertArrayHasKey($value, $this->decoded);
            }
        }

        return $this;
    }

    /**
     * Assert that the response is a superset of the given JSON.
     */
    public function assertSubset(array $data, bool $strict = false): static
    {
        PHPUnit::assertArraySubset(
            $data,
            $this->decoded,
            $strict,
            $this->assertJsonMessage($data)
        );

        return $this;
    }

    /**
     * Reorder associative array keys to make it easy to compare arrays.
     */
    protected function reorderAssocKeys(array $data): array
    {
        $data = Arr::dot($data);
        ksort($data);

        $result = [];

        foreach ($data as $key => $value) {
            Arr::set($result, $key, $value);
        }

        return $result;
    }

    /**
     * Get the assertion message for assertJson.
     */
    protected function assertJsonMessage(array $data): string
    {
        $expected = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $actual = json_encode($this->decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'Unable to find JSON: ' . PHP_EOL . PHP_EOL
            . "[{$expected}]" . PHP_EOL . PHP_EOL
            . 'within response JSON:' . PHP_EOL . PHP_EOL
            . "[{$actual}]." . PHP_EOL . PHP_EOL;
    }

    /**
     * Get the strings we need to search for when examining the JSON.
     */
    protected function jsonSearchStrings(string $key, mixed $value): array
    {
        $needle = Str::substr(json_encode([$key => $value], JSON_UNESCAPED_UNICODE), 1, -1);

        return [
            $needle . ']',
            $needle . '}',
            $needle . ',',
        ];
    }

    /**
     * Get the total number of items in the underlying JSON array.
     */
    public function count(): int
    {
        return count($this->decoded);
    }

    /**
     * Determine whether an offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->decoded[$offset]);
    }

    /**
     * Get the value at the given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->decoded[$offset];
    }

    /**
     * Set the value at the given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->decoded[$offset] = $value;
    }

    /**
     * Unset the value at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->decoded[$offset]);
    }
}
