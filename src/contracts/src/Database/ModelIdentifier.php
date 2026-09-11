<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Database;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Relations\Relation;

/**
 * Do not use constructor property promotion here.
 *
 * The order these properties are declared in is part of the serialized output,
 * and Laravel expects that exact order. If this class is switched to constructor
 * property promotion, PHP will change the property declaration order and the
 * serialized string will no longer match Laravel.
 */
class ModelIdentifier
{
    /**
     * Use the Relation morphMap for a Model's name when serializing.
     */
    protected static bool $useMorphMap = false;

    /**
     * The class name of the model, or its string or integer morph-map alias when enabled.
     */
    public int|string|null $class;

    /**
     * The unique identifier of the model.
     *
     * This may be either a single ID or an array of IDs.
     */
    public mixed $id;

    /**
     * The relationships loaded on the model.
     *
     * @var array<int, string>
     */
    public array $relations;

    /**
     * The connection name of the model.
     */
    public ?string $connection;

    /**
     * The class name of the model collection.
     *
     * @var null|class-string<Collection>
     */
    public ?string $collectionClass = null;

    /**
     * Create a new model identifier.
     *
     * @param null|class-string $class
     * @param mixed $id this may be either a single ID or an array of IDs
     * @param array $relations the relationships loaded on the model
     * @param null|string $connection the connection name of the model
     */
    public function __construct(?string $class, mixed $id, array $relations, ?string $connection = null)
    {
        if ($class !== null && static::$useMorphMap) {
            $class = Relation::getMorphAlias($class);
        }

        $this->class = $class;
        $this->id = $id;
        $this->relations = $relations;
        $this->connection = $connection;
    }

    /**
     * Specify the collection class that should be used when serializing / restoring collections.
     *
     * @param null|class-string<Collection> $collectionClass
     */
    public function useCollectionClass(?string $collectionClass): static
    {
        $this->collectionClass = $collectionClass;

        return $this;
    }

    /**
     * Get the fully-qualified class name of the Model.
     */
    public function getClass(): ?string
    {
        $class = $this->class;

        if (static::$useMorphMap && $class !== null) {
            $class = Relation::getMorphedModel($class) ?? $class;
        }

        // Unmapped integer aliases still follow the nullable-string getter contract.
        return $class === null ? null : (string) $class;
    }

    /**
     * Indicate whether to use the relational morph-map when serializing Models.
     *
     * Boot-only. The flag persists in a static property for the worker lifetime
     * and applies to every model identifier serialization across all coroutines.
     */
    public static function useMorphMap(bool $useMorphMap = true): void
    {
        static::$useMorphMap = $useMorphMap;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$useMorphMap = false;
    }
}
