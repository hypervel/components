<?php

declare(strict_types=1);

namespace Hypervel\Database;

class ModelIdentifier
{
    /**
     * The class name of the model collection.
     *
     * @var class-string|null
     */
    public ?string $collectionClass = null;

    /**
     * Create a new model identifier.
     *
     * @param  class-string  $class
     * @param  mixed  $id  This may be either a single ID or an array of IDs.
     * @param  array  $relations  The relationships loaded on the model.
     * @param  string|null  $connection  The connection name of the model.
     */
    public function __construct(
        public string $class,
        public mixed $id,
        public array $relations,
        public ?string $connection = null
    ) {
    }

    /**
     * Specify the collection class that should be used when serializing / restoring collections.
     *
     * @param  class-string|null  $collectionClass
     */
    public function useCollectionClass(?string $collectionClass): static
    {
        $this->collectionClass = $collectionClass;

        return $this;
    }
}
