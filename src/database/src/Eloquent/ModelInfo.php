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
 * @template TModel of Model = Model
 *
 * @implements Arrayable<string, mixed>
 *
 * @internal
 */
class ModelInfo implements Arrayable, ArrayAccess
{
    /**
     * Create a new model info instance.
     *
     * @param class-string<TModel> $class the model's fully-qualified class
     * @param null|string $database the database connection name
     * @param string $table the database table name
     * @param null|class-string $policy the policy that applies to the model
     * @param BaseCollection<int, array<string, mixed>> $attributes the attributes available on the model
     * @param BaseCollection<int, array{name: string, type: string, related: class-string<Model>}> $relations the relations defined on the model
     * @param BaseCollection<int, array{event: string, class: string}> $events the events that the model dispatches
     * @param BaseCollection<int, array{event: string, observer: array<int, string>}> $observers the observers registered for the model
     * @param class-string<Collection<array-key, TModel>> $collection the Collection class that collects the models
     * @param class-string<Builder<TModel>> $builder the Builder class registered for the model
     * @param null|class-string<JsonResource> $resource the JSON resource class that represents the model
     */
    public function __construct(
        public string $class,
        public ?string $database,
        public string $table,
        public ?string $policy,
        public BaseCollection $attributes,
        public BaseCollection $relations,
        public BaseCollection $events,
        public BaseCollection $observers,
        public string $collection,
        public string $builder,
        public ?string $resource
    ) {
    }

    /**
     * Convert the model info to an array.
     *
     * @return array{
     *     "class": class-string<TModel>,
     *     database: null|string,
     *     table: string,
     *     policy: null|class-string,
     *     attributes: BaseCollection<int, array<string, mixed>>,
     *     relations: BaseCollection<int, array{name: string, type: string, related: class-string<Model>}>,
     *     events: BaseCollection<int, array{event: string, class: string}>,
     *     observers: BaseCollection<int, array{event: string, observer: array<int, string>}>,
     *     collection: class-string<Collection<array-key, TModel>>,
     *     builder: class-string<Builder<TModel>>,
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

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    /**
     * Get the value for a given offset.
     *
     * @throws InvalidArgumentException
     */
    public function offsetGet(mixed $offset): mixed
    {
        return property_exists($this, $offset) ? $this->{$offset} : throw new InvalidArgumentException("Property {$offset} does not exist.");
    }

    /**
     * Set the value at the given offset.
     *
     * @throws LogicException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException(self::class . ' may not be mutated using array access.');
    }

    /**
     * Unset the value at the given offset.
     *
     * @throws LogicException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException(self::class . ' may not be mutated using array access.');
    }
}
