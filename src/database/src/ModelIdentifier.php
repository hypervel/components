<?php

declare(strict_types=1);

namespace Hypervel\Database;

class ModelIdentifier
{
    /**
     * The class name of the model collection.
     */
    public ?string $collectionClass;

    /**
     * Create a new model identifier.
     *
     * @param string $class the class name of the model
     * @param mixed $id this may be either a single ID or an array of IDs
     * @param array $relations the relationships loaded on the model
     * @param mixed $connection the connection name of the model
     */
    public function __construct(
        public string $class,
        public mixed $id,
        public array $relations,
        public mixed $connection = null
    ) {
    }

    /**
     * Specify the collection class that should be used when serializing / restoring collections.
     */
    public function useCollectionClass(?string $collectionClass): static
    {
        $this->collectionClass = $collectionClass;

        return $this;
    }
}
