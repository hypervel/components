<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;
use Hypervel\Head\Schema\SchemaObject;

/**
 * @phpstan-consistent-constructor
 *
 * @phpstan-type SchemaData array<string, mixed>
 */
class Schemas extends GroupedTagBuilder
{
    /**
     * Create the schema collection.
     *
     * @param array<string, SchemaData|SchemaObject> $schemas
     */
    public function __construct(protected array $schemas = [])
    {
    }

    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'schemas';
    }

    /**
     * Get the supported route attribute names.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return ['schema'];
    }

    /**
     * Build schemas from a route attribute.
     */
    public static function fromRouteAttribute(string $key, mixed $value): ?self
    {
        if ($key !== 'schema' || (! $value instanceof SchemaObject && ! is_array($value))) {
            return null;
        }

        $builder = new self;

        if ($value instanceof SchemaObject || ! array_is_list($value)) {
            return $builder->schema($value instanceof SchemaObject ? $value : self::named($value));
        }

        foreach ($value as $schema) {
            if ($schema instanceof SchemaObject || is_array($schema)) {
                $builder->schema($schema instanceof SchemaObject ? $schema : self::named($schema));
            }
        }

        return $builder;
    }

    /**
     * Add a schema, deduplicating objects by identity and arrays by content.
     *
     * @param SchemaData|SchemaObject $schema
     */
    public function schema(SchemaObject|array $schema): static
    {
        $this->schemas[$schema instanceof SchemaObject ? spl_object_hash($schema) : hash('xxh128', json_encode($schema, JSON_THROW_ON_ERROR))] = $schema;

        return $this;
    }

    /**
     * Overlay these schemas onto the lower layer.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        if (! $base instanceof self) {
            return $this;
        }

        return new static(array_replace($base->schemas, $this->schemas));
    }

    /**
     * Determine whether no schemas are defined.
     */
    public function isEmpty(): bool
    {
        return $this->schemas === [];
    }

    /**
     * Validate and resolve all schemas for the head array.
     *
     * @return array<int, SchemaData>
     */
    public function toHeadArray(ResolvedHead $head): array
    {
        return array_map(function (SchemaObject|array $schema) use ($head): array {
            $schemaData = $schema instanceof SchemaObject ? $schema->toJsonLd() : $schema;

            $head->validateSchema($schemaData);

            return $schemaData;
        }, $this->headArray());
    }

    /**
     * Render each schema as JSON-LD.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        return array_map(
            fn (array $schema): string => $tags->jsonLd($schema),
            $this->toHeadArray($head),
        );
    }

    /**
     * Get the ordered schema definitions.
     *
     * @return array<int, SchemaData|SchemaObject>
     */
    protected function headArray(): array
    {
        return array_values($this->schemas);
    }

    /**
     * Copy mutable schema objects for independent coroutine state.
     */
    public function __clone(): void
    {
        $schemas = [];

        foreach ($this->schemas as $key => $schema) {
            if ($schema instanceof SchemaObject) {
                $schema = clone $schema;
                // Original identities can be reused after their objects are released.
                $key = spl_object_hash($schema);
            }

            $schemas[$key] = $schema;
        }

        $this->schemas = $schemas;
    }
}
