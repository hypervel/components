<?php

declare(strict_types=1);

namespace Hypervel\View;

use ArrayAccess;
use ArrayIterator;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Traversable;

class ComponentAttributeBag implements ArrayAccess, IteratorAggregate, JsonSerializable, Htmlable, Stringable
{
    use Conditionable, Macroable;

    /**
     * The raw array of attributes.
     */
    protected array $attributes = [];

    /**
     * Create a new component attribute bag instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->setAttributes($attributes);
    }

    /**
     * Get all of the attribute values.
     */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Get the first attribute's value.
     */
    public function first(mixed $default = null): mixed
    {
        return $this->getIterator()->current() ?? value($default);
    }

    /**
     * Get a given attribute from the attribute array.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? value($default);
    }

    /**
     * Determine if a given attribute exists in the attribute array.
     */
    public function has(array|string $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if (! array_key_exists($value, $this->attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if any of the keys exist in the attribute array.
     */
    public function hasAny(array|string $key): bool
    {
        if (! count($this->attributes)) {
            return false;
        }

        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if ($this->has($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given attribute is missing from the attribute array.
     */
    public function missing(string $key): bool
    {
        return ! $this->has($key);
    }

    /**
     * Only include the given attribute from the attribute array.
     */
    public function only(mixed $keys): static
    {
        if (is_null($keys)) {
            $values = $this->attributes;
        } else {
            $keys = Arr::wrap($keys);

            $values = Arr::only($this->attributes, $keys);
        }

        return new static($values);
    }

    /**
     * Exclude the given attribute from the attribute array.
     */
    public function except(mixed $keys): static
    {
        if (is_null($keys)) {
            $values = $this->attributes;
        } else {
            $keys = Arr::wrap($keys);

            $values = Arr::except($this->attributes, $keys);
        }

        return new static($values);
    }

    /**
     * Filter the attributes, returning a bag of attributes that pass the filter.
     */
    public function filter(callable $callback): static
    {
        return new static((new Collection($this->attributes))->filter($callback)->all());
    }

    /**
     * Return a bag of attributes that have keys starting with the given value / pattern.
     *
     * @param  string|string[]  $needles
     */
    public function whereStartsWith(string|array $needles): static
    {
        return $this->filter(function ($value, $key) use ($needles) {
            return Str::startsWith($key, $needles);
        });
    }

    /**
     * Return a bag of attributes with keys that do not start with the given value / pattern.
     *
     * @param  string|string[]  $needles
     */
    public function whereDoesntStartWith(string|array $needles): static
    {
        return $this->filter(function ($value, $key) use ($needles) {
            return ! Str::startsWith($key, $needles);
        });
    }

    /**
     * Return a bag of attributes that have keys starting with the given value / pattern.
     *
     * @param  string|string[]  $needles
     */
    public function thatStartWith(string|array $needles): static
    {
        return $this->whereStartsWith($needles);
    }

    /**
     * Only include the given attribute from the attribute array.
     */
    public function onlyProps(mixed $keys): static
    {
        return $this->only(static::extractPropNames($keys));
    }

    /**
     * Exclude the given attribute from the attribute array.
     */
    public function exceptProps(mixed $keys): static
    {
        return $this->except(static::extractPropNames($keys));
    }

    /**
     * Conditionally merge classes into the attribute bag.
     */
    public function class(mixed $classList): static
    {
        $classList = Arr::wrap($classList);

        return $this->merge(['class' => Arr::toCssClasses($classList)]);
    }

    /**
     * Conditionally merge styles into the attribute bag.
     */
    public function style(mixed $styleList): static
    {
        $styleList = Arr::wrap($styleList);

        return $this->merge(['style' => Arr::toCssStyles($styleList)]);
    }

    /**
     * Merge additional attributes / values into the attribute bag.
     */
    public function merge(array $attributeDefaults = [], bool $escape = true): static
    {
        $attributeDefaults = array_map(function ($value) use ($escape) {
            return $this->shouldEscapeAttributeValue($escape, $value)
                        ? e($value)
                        : $value;
        }, $attributeDefaults);

        [$appendableAttributes, $nonAppendableAttributes] = (new Collection($this->attributes))
            ->partition(function ($value, $key) use ($attributeDefaults) {
                return $key === 'class' || $key === 'style' || (
                    isset($attributeDefaults[$key]) &&
                    $attributeDefaults[$key] instanceof AppendableAttributeValue
                );
            });

        $attributes = $appendableAttributes->mapWithKeys(function ($value, $key) use ($attributeDefaults, $escape) {
            $defaultsValue = isset($attributeDefaults[$key]) && $attributeDefaults[$key] instanceof AppendableAttributeValue
                        ? $this->resolveAppendableAttributeDefault($attributeDefaults, $key, $escape)
                        : ($attributeDefaults[$key] ?? '');

            if ($key === 'style') {
                $value = Str::finish($value, ';');
            }

            return [$key => implode(' ', array_unique(array_filter([$defaultsValue, $value])))];
        })->merge($nonAppendableAttributes)->all();

        return new static(array_merge($attributeDefaults, $attributes));
    }

    /**
     * Determine if the specific attribute value should be escaped.
     */
    protected function shouldEscapeAttributeValue(bool $escape, mixed $value): bool
    {
        if (! $escape) {
            return false;
        }

        return ! is_object($value) &&
               ! is_null($value) &&
               ! is_bool($value);
    }

    /**
     * Create a new appendable attribute value.
     */
    public function prepends(mixed $value): AppendableAttributeValue
    {
        return new AppendableAttributeValue($value);
    }

    /**
     * Resolve an appendable attribute value default value.
     */
    protected function resolveAppendableAttributeDefault(array $attributeDefaults, string $key, bool $escape): mixed
    {
        if ($this->shouldEscapeAttributeValue($escape, $value = $attributeDefaults[$key]->value)) {
            $value = e($value);
        }

        return $value;
    }

    /**
     * Determine if the attribute bag is empty.
     */
    public function isEmpty(): bool
    {
        return trim((string) $this) === '';
    }

    /**
     * Determine if the attribute bag is not empty.
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Get all of the raw attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set the underlying attributes.
     */
    public function setAttributes(array $attributes): void
    {
        if (isset($attributes['attributes']) &&
            $attributes['attributes'] instanceof self) {
            $parentBag = $attributes['attributes'];

            unset($attributes['attributes']);

            $attributes = $parentBag->merge($attributes, $escape = false)->getAttributes();
        }

        $this->attributes = $attributes;
    }

    /**
     * Extract "prop" names from given keys.
     */
    public static function extractPropNames(array $keys): array
    {
        $props = [];

        foreach ($keys as $key => $default) {
            $key = is_numeric($key) ? $default : $key;

            $props[] = $key;
            $props[] = Str::kebab($key);
        }

        return $props;
    }

    /**
     * Get content as a string of HTML.
     */
    public function toHtml(): string
    {
        return (string) $this;
    }

    /**
     * Merge additional attributes / values into the attribute bag.
     */
    public function __invoke(array $attributeDefaults = []): HtmlString
    {
        return new HtmlString((string) $this->merge($attributeDefaults));
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * Get the value at the given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Set the value at a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    /**
     * Remove the value at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /**
     * Get an iterator for the items.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }

    /**
     * Convert the object into a JSON serializable form.
     */
    public function jsonSerialize(): mixed
    {
        return $this->attributes;
    }

    /**
     * Implode the attributes into a single HTML ready string.
     */
    public function __toString(): string
    {
        $string = '';

        foreach ($this->attributes as $key => $value) {
            if ($value === false || is_null($value)) {
                continue;
            }

            if ($value === true) {
                $value = $key === 'x-data' || str_starts_with($key, 'wire:') ? '' : $key;
            }

            $string .= ' '.$key.'="'.str_replace('"', '\\"', trim((string) $value)).'"';
        }

        return trim($string);
    }
}
