<?php

declare(strict_types=1);

namespace Hypervel\Testing\Fluent;

use Closure;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use Hypervel\Testing\AssertableJsonString;
use PHPUnit\Framework\Assert as PHPUnit;

class AssertableJson implements Arrayable
{
    use Concerns\Has;
    use Concerns\Matching;
    use Concerns\Debugging;
    use Concerns\Interaction;
    use Conditionable;
    use Macroable;
    use Tappable;

    /**
     * The properties in the current scope.
     */
    private array $props;

    /**
     * The "dot" path to the current scope.
     */
    private ?string $path;

    /**
     * Create a new fluent, assertable JSON data instance.
     */
    protected function __construct(array $props, ?string $path = null)
    {
        $this->path = $path;
        $this->props = $props;
    }

    /**
     * Compose the absolute "dot" path to the given key.
     */
    protected function dotPath(string $key = ''): string
    {
        if (is_null($this->path)) {
            return $key;
        }

        return rtrim(implode('.', [$this->path, $key]), '.');
    }

    /**
     * Retrieve a prop within the current scope using "dot" notation.
     */
    protected function prop(?string $key = null): mixed
    {
        return Arr::get($this->props, $key);
    }

    /**
     * Instantiate a new "scope" at the path of the given key.
     */
    protected function scope(string|int $key, Closure $callback): static
    {
        $props = $this->prop((string) $key);
        $path = $this->dotPath((string) $key);

        PHPUnit::assertIsArray($props, sprintf('Property [%s] is not scopeable.', $path));

        $scope = new static($props, $path);
        $callback($scope);
        $scope->interacted();

        return $this;
    }

    /**
     * Instantiate a new "scope" on the first child element.
     */
    public function first(Closure $callback): static
    {
        $props = $this->prop();

        $path = $this->dotPath();

        PHPUnit::assertNotEmpty(
            $props,
            $path === ''
            ? 'Cannot scope directly onto the first element of the root level because it is empty.'
            : sprintf('Cannot scope directly onto the first element of property [%s] because it is empty.', $path)
        );

        $key = array_keys($props)[0];

        $this->interactsWith($key);

        return $this->scope($key, $callback);
    }

    /**
     * Instantiate a new "scope" on each child element.
     */
    public function each(Closure $callback): static
    {
        $props = $this->prop();

        $path = $this->dotPath();

        PHPUnit::assertNotEmpty(
            $props,
            $path === ''
            ? 'Cannot scope directly onto each element of the root level because it is empty.'
            : sprintf('Cannot scope directly onto each element of property [%s] because it is empty.', $path)
        );

        foreach (array_keys($props) as $key) {
            $this->interactsWith($key);

            $this->scope($key, $callback);
        }

        return $this;
    }

    /**
     * Create a new instance from an array.
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    /**
     * Create a new instance from an AssertableJsonString.
     */
    public static function fromAssertableJsonString(AssertableJsonString $json): static
    {
        return static::fromArray($json->json());
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->props;
    }
}
