<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\Eloquent\Relations\Relation;

/**
 * NOTE: Do not use constructor property promotion here.
 *
 * The order these properties are declared in is part of the serialized output,
 * and Laravel expects that exact order. If this class is switched to constructor
 * property promotion, PHP will change the property declaration order and the
 * serialized string will no longer match Laravel.
 *
 * Keep these properties explicitly declared in this exact order:
 * class, id, relations, connection, collectionClass.
 */
class ModelIdentifier
{
    /**
     * Use the Relation morphMap for a Model's name when serializing.
     */
    protected static bool $useMorphMap = false;

    /**
     * The class name of the model.
     *
     * @var null|class-string
     */
    public ?string $class;

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
     * @var null|class-string
     */
    public ?string $collectionClass = null;

    /**
     * Create a new model identifier.
     *
     * @param class-string $class
     * @param mixed $id this may be either a single ID or an array of IDs
     * @param array $relations the relationships loaded on the model
     * @param null|string $connection the connection name of the model
     */
    public function __construct(?string $class, mixed $id, array $relations, ?string $connection = null)
    {
        if ($class !== null && static::$useMorphMap) {
            $class = (string) Relation::getMorphAlias($class);
        }

        $this->class = $class;
        $this->id = $id;
        $this->relations = $relations;
        $this->connection = $connection;
    }

    /**
     * Specify the collection class that should be used when serializing / restoring collections.
     *
     * @param null|class-string $collectionClass
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
        if ($this->class === null) {
            return null;
        }

        return Relation::getMorphedModel($this->class) ?? $this->class;
    }

    /**
     * Indicate whether to use the relational morph-map when serializing Models.
     */
    public static function useMorphMap(bool $useMorphMap = true): void
    {
        static::$useMorphMap = $useMorphMap;
    }
}
