<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use ArrayAccess;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use LogicException;

/**
 * @implements Arrayable<string, mixed>
 */
class ModelInfo implements Arrayable, ArrayAccess
{
    /**
     * @template TModel of \Hypervel\Database\Eloquent\Model
     *
     * @param class-string<TModel> $class the model's fully-qualified class
     * @param string $database the database connection name
     * @param string $table the database table name
     * @param null|class-string $policy the policy that applies to the model
     * @param \Hypervel\Support\Collection<int, array<string, mixed>> $attributes the attributes available on the model
     * @param \Hypervel\Support\Collection<int, array{name: string, type: string, related: class-string<\Hypervel\Database\Eloquent\Model>}> $relations the relations defined on the model
     * @param \Hypervel\Support\Collection<int, array{event: string, class: string}> $events the events that the model dispatches
     * @param \Hypervel\Support\Collection<int, array{event: string, observer: array<int, string>}> $observers the observers registered for the model
     * @param class-string<\Hypervel\Database\Eloquent\Collection<TModel>> $collection the Collection class that collects the models
     * @param class-string<\Hypervel\Database\Eloquent\Builder<TModel>> $builder the Builder class registered for the model
     * @param null|class-string<\Hypervel\Http\Resources\Json\JsonResource> $resource the JSON resource class that represents the model
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
     *     "class": class-string<\Hypervel\Database\Eloquent\Model>,
     *     database: string,
     *     table: string,
     *     policy: null|class-string,
     *     attributes: \Hypervel\Support\Collection<int, array<string, mixed>>,
     *     relations: \Hypervel\Support\Collection<int, array{name: string, type: string, related: class-string<\Hypervel\Database\Eloquent\Model>}>,
     *     events: \Hypervel\Support\Collection<int, array{event: string, class: string}>,
     *     observers: \Hypervel\Support\Collection<int, array{event: string, observer: array<int, string>}>,
     *     collection: class-string<\Hypervel\Database\Eloquent\Collection<\Hypervel\Database\Eloquent\Model>>,
     *     builder: class-string<\Hypervel\Database\Eloquent\Builder<\Hypervel\Database\Eloquent\Model>>,
     *     resource: null|class-string<\Hypervel\Http\Resources\Json\JsonResource>
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
