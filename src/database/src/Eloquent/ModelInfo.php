<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use ArrayAccess;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Support\Collection as BaseCollection;
use InvalidArgumentException;
use LogicException;

/**
 * @implements Arrayable<string, mixed>
 */
class ModelInfo implements Arrayable, ArrayAccess
{
    /**
     * @param class-string<Model> $class the model's fully-qualified class
     * @param string $database the database connection name
     * @param string $table the database table name
     * @param null|class-string $policy the policy that applies to the model
     * @param BaseCollection<int, array<string, mixed>> $attributes the attributes available on the model
     * @param BaseCollection<int, array{name: string, type: string, related: class-string<Model>}> $relations the relations defined on the model
     * @param BaseCollection<int, array{event: string, class: string}> $events the events that the model dispatches
     * @param BaseCollection<int, array{event: string, observer: array<int, string>}> $observers the observers registered for the model
     * @param class-string<Collection<Model>> $collection the Collection class that collects the models
     * @param class-string<Builder<Model>> $builder the Builder class registered for the model
     * @param null|class-string<JsonResource> $resource the JSON resource class that represents the model
     */
    public function __construct(
        public $class,
        public $database,
        public $table,
        public $policy,
        public $attributes,
        public $relations,
        public $events,
        public $observers,
        public $collection,
        public $builder,
        public $resource
    ) {
    }

    /**
     * Convert the model info to an array.
     *
     * @return array{
     *     "class": class-string<Model>,
     *     database: string,
     *     table: string,
     *     policy: null|class-string,
     *     attributes: BaseCollection<int, array<string, mixed>>,
     *     relations: BaseCollection<int, array{name: string, type: string, related: class-string<Model>}>,
     *     events: BaseCollection<int, array{event: string, class: string}>,
     *     observers: BaseCollection<int, array{event: string, observer: array<int, string>}>,
     *     collection: class-string<Collection<Model>>,
     *     builder: class-string<Builder<Model>>,
     *     resource: null|class-string<JsonResource>
     * }
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'database' => $this->database,
            'table' => $this->table,
            'policy' => $this->policy,
            'attributes' => $this->attributes,
            'relations' => $this->relations,
            'events' => $this->events,
            'observers' => $this->observers,
            'collection' => $this->collection,
            'builder' => $this->builder,
            'resource' => $this->resource,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return property_exists($this, $offset) ? $this->{$offset} : throw new InvalidArgumentException("Property {$offset} does not exist.");
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException(self::class . ' may not be mutated using array access.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException(self::class . ' may not be mutated using array access.');
    }
}
