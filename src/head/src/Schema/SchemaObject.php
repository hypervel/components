<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use BadMethodCallException;
use DateTimeInterface;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Head\SchemaType;
use JsonSerializable;
use ReflectionClass;

/**
 * @implements Arrayable<array<string, mixed>>
 */
abstract class SchemaObject implements Arrayable, JsonSerializable, Transient
{
    protected string $type;

    /** @var array<string, mixed> */
    protected array $properties = [];

    /** @var array<class-string<static>, string> */
    protected static array $types = [];

    /**
     * Create a schema with an explicit or inferred type.
     */
    public function __construct(?string $type = null)
    {
        $this->type = $type ?? $this->typeFromAttribute();
    }

    /**
     * Set a schema property, coercing nested schema objects and dates.
     */
    public function set(string $property, mixed $value): static
    {
        $this->properties[$property] = $this->coerce($value);

        return $this;
    }

    /**
     * Set a date property using an ISO 8601 value.
     */
    public function date(string $property, DateTimeInterface|string $date): static
    {
        return $this->set($property, $date instanceof DateTimeInterface ? $date->format(DateTimeInterface::ATOM) : $date);
    }

    /**
     * Get the schema type and properties.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['@type' => $this->type] + $this->properties;
    }

    /**
     * Get the schema with its JSON-LD context.
     *
     * @return array<string, mixed>
     */
    public function toJsonLd(): array
    {
        return ['@context' => 'https://schema.org'] + $this->toArray();
    }

    /**
     * Get the schema's JSON representation.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toJsonLd();
    }

    /**
     * Dynamically set the schema property named by the called method.
     *
     * @param array<int|string, mixed> $parameters
     */
    public function __call(string $method, array $parameters): static
    {
        if (count($parameters) > 1) {
            throw new BadMethodCallException(sprintf(
                'Magic schema property setters accept a single value, and [%s] does not define a [%s] method.',
                static::class,
                $method,
            ));
        }

        return $this->set($method, $parameters === [] ? true : $parameters[array_key_first($parameters)]);
    }

    /**
     * Normalize nested schemas and dates into their serialized values.
     */
    protected function coerce(mixed $value): mixed
    {
        if ($value instanceof SchemaObject) {
            return $value->toArray();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            return array_map($this->coerce(...), $value);
        }

        return $value;
    }

    /**
     * Resolve the schema.org type from the SchemaType attribute or the class name.
     */
    protected function typeFromAttribute(): string
    {
        if (isset(static::$types[static::class])) {
            return static::$types[static::class];
        }

        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(SchemaType::class);

        return static::$types[static::class] = $attributes === []
            ? $reflection->getShortName()
            : $attributes[0]->newInstance()->name;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$types = [];
    }
}
