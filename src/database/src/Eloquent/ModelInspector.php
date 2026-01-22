<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Facades\Gate;
use Hypervel\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileObject;

use function Hypervel\Support\enum_value;

class ModelInspector
{
    /**
     * The methods that can be called in a model to indicate a relation.
     *
     * @var list<string>
     */
    protected array $relationMethods = [
        'hasMany',
        'hasManyThrough',
        'hasOneThrough',
        'belongsToMany',
        'hasOne',
        'belongsTo',
        'morphOne',
        'morphTo',
        'morphMany',
        'morphToMany',
        'morphedByMany',
    ];

    /**
     * Create a new model inspector instance.
     */
    public function __construct(
        protected Application $app,
    ) {
    }

    /**
     * Extract model details for the given model.
     *
     * @param  class-string<Model>|string  $model
     * @return array{class: class-string<Model>, database: string, table: string, policy: class-string|null, attributes: BaseCollection<int, array<string, mixed>>, relations: BaseCollection<int, array<string, mixed>>, events: BaseCollection<int, array<string, mixed>>, observers: BaseCollection<int, array<string, mixed>>, collection: class-string<Collection<array-key, Model>>, builder: class-string<Builder<Model>>, resource: class-string|null}
     *
     * @throws \Hypervel\Container\BindingResolutionException
     */
    public function inspect(string $model, ?string $connection = null): array
    {
        $class = $this->qualifyModel($model);

        /** @var \Hypervel\Database\Eloquent\Model $model */
        $model = $this->app->make($class);

        if ($connection !== null) {
            $model->setConnection($connection);
        }

        return [
            'class' => get_class($model),
            'database' => $model->getConnection()->getName(),
            'table' => $model->getConnection()->getTablePrefix().$model->getTable(),
            'policy' => $this->getPolicy($model),
            'attributes' => $this->getAttributes($model),
            'relations' => $this->getRelations($model),
            'events' => $this->getEvents($model),
            'observers' => $this->getObservers($model),
            'collection' => $this->getCollectedBy($model),
            'builder' => $this->getBuilder($model),
            'resource' => $this->getResource($model),
        ];
    }

    /**
     * Get the column attributes for the given model.
     *
     * @return BaseCollection<int, array<string, mixed>>
     */
    protected function getAttributes(Model $model): BaseCollection
    {
        $connection = $model->getConnection();
        $schema = $connection->getSchemaBuilder();
        $table = $model->getTable();
        $columns = $schema->getColumns($table);
        $indexes = $schema->getIndexes($table);

        return (new BaseCollection($columns))
            ->map(fn ($column) => [
                'name' => $column['name'],
                'type' => $column['type'],
                'increments' => $column['auto_increment'],
                'nullable' => $column['nullable'],
                'default' => $this->getColumnDefault($column, $model),
                'unique' => $this->columnIsUnique($column['name'], $indexes),
                'fillable' => $model->isFillable($column['name']),
                'hidden' => $this->attributeIsHidden($column['name'], $model),
                'appended' => null,
                'cast' => $this->getCastType($column['name'], $model),
            ])
            ->merge($this->getVirtualAttributes($model, $columns));
    }

    /**
     * Get the virtual (non-column) attributes for the given model.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return BaseCollection<int, array<string, mixed>>
     */
    protected function getVirtualAttributes(Model $model, array $columns): BaseCollection
    {
        $class = new ReflectionClass($model);

        return (new BaseCollection($class->getMethods()))
            ->reject(
                fn (ReflectionMethod $method) => $method->isStatic()
                    || $method->isAbstract()
                    || $method->getDeclaringClass()->getName() === Model::class
            )
            ->mapWithKeys(function (ReflectionMethod $method) use ($model) {
                if (preg_match('/^get(.+)Attribute$/', $method->getName(), $matches) === 1) {
                    return [Str::snake($matches[1]) => 'accessor'];
                } elseif ($model->hasAttributeMutator($method->getName())) {
                    return [Str::snake($method->getName()) => 'attribute'];
                } else {
                    return [];
                }
            })
            ->reject(fn ($cast, $name) => (new BaseCollection($columns))->contains('name', $name))
            ->map(fn ($cast, $name) => [
                'name' => $name,
                'type' => null,
                'increments' => false,
                'nullable' => null,
                'default' => null,
                'unique' => null,
                'fillable' => $model->isFillable($name),
                'hidden' => $this->attributeIsHidden($name, $model),
                'appended' => $model->hasAppended($name),
                'cast' => $cast,
            ])
            ->values();
    }

    /**
     * Get the relations from the given model.
     *
     * @return BaseCollection<int, array<string, mixed>>
     */
    protected function getRelations(Model $model): BaseCollection
    {
        return (new BaseCollection(get_class_methods($model)))
            ->map(fn ($method) => new ReflectionMethod($model, $method))
            ->reject(
                fn (ReflectionMethod $method) => $method->isStatic()
                    || $method->isAbstract()
                    || $method->getDeclaringClass()->getName() === Model::class
                    || $method->getNumberOfParameters() > 0
            )
            ->filter(function (ReflectionMethod $method) {
                if ($method->getReturnType() instanceof ReflectionNamedType
                    && is_subclass_of($method->getReturnType()->getName(), Relation::class)) {
                    return true;
                }

                $file = new SplFileObject($method->getFileName());
                $file->seek($method->getStartLine() - 1);
                $code = '';
                while ($file->key() < $method->getEndLine()) {
                    $code .= trim($file->current());
                    $file->next();
                }

                return (new BaseCollection($this->relationMethods))
                    ->contains(fn ($relationMethod) => str_contains($code, '$this->'.$relationMethod.'('));
            })
            ->map(function (ReflectionMethod $method) use ($model) {
                $relation = $method->invoke($model);

                if (! $relation instanceof Relation) {
                    return null;
                }

                return [
                    'name' => $method->getName(),
                    'type' => Str::afterLast(get_class($relation), '\\'),
                    'related' => get_class($relation->getRelated()),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Get the first policy associated with this model.
     *
     * @return class-string|null
     */
    protected function getPolicy(Model $model): ?string
    {
        $policy = Gate::getPolicyFor($model::class);

        return $policy ? $policy::class : null;
    }

    /**
     * Get the events that the model dispatches.
     *
     * @return BaseCollection<int, array{event: string, class: string}>
     */
    protected function getEvents(Model $model): BaseCollection
    {
        return (new BaseCollection($model->dispatchesEvents()))
            ->map(fn (string $class, string $event) => [
                'event' => $event,
                'class' => $class,
            ])->values();
    }

    /**
     * Get the observers watching this model.
     *
     * @return BaseCollection<int, array{event: string, observer: array<int, class-string>}>
     */
    protected function getObservers(Model $model): BaseCollection
    {
        $modelListener = $this->app->make(ModelListener::class);
        $observers = $modelListener->getObservers($model::class);

        $formatted = [];

        foreach ($observers as $event => $observerClasses) {
            $formatted[] = [
                'event' => $event,
                'observer' => $observerClasses,
            ];
        }

        return new BaseCollection($formatted);
    }

    /**
     * Get the collection class being used by the model.
     *
     * @return class-string<Collection<array-key, Model>>
     */
    protected function getCollectedBy(Model $model): string
    {
        return $model->newCollection()::class;
    }

    /**
     * Get the builder class being used by the model.
     *
     * @return class-string<Builder<Model>>
     */
    protected function getBuilder(Model $model): string
    {
        return $model->newQuery()::class;
    }

    /**
     * Get the class used for JSON response transforming.
     *
     * @return class-string|null
     */
    protected function getResource(Model $model): ?string
    {
        return rescue(static fn () => $model->toResource()::class, null, false);
    }

    /**
     * Qualify the given model class base name.
     *
     * @return class-string<Model>
     *
     * @see \Hypervel\Console\GeneratorCommand
     */
    protected function qualifyModel(string $model): string
    {
        if (str_contains($model, '\\') && class_exists($model)) {
            return $model;
        }

        $model = ltrim($model, '\\/');

        $model = str_replace('/', '\\', $model);

        $rootNamespace = $this->app->getNamespace();

        if (Str::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Models'))
            ? $rootNamespace.'Models\\'.$model
            : $rootNamespace.$model;
    }

    /**
     * Get the cast type for the given column.
     */
    protected function getCastType(string $column, Model $model): ?string
    {
        if ($model->hasGetMutator($column) || $model->hasSetMutator($column)) {
            return 'accessor';
        }

        if ($model->hasAttributeMutator($column)) {
            return 'attribute';
        }

        return $this->getCastsWithDates($model)->get($column) ?? null;
    }

    /**
     * Get the model casts, including any date casts.
     *
     * @return BaseCollection<string, string>
     */
    protected function getCastsWithDates(Model $model): BaseCollection
    {
        return (new BaseCollection($model->getDates()))
            ->filter()
            ->flip()
            ->map(fn () => 'datetime')
            ->merge($model->getCasts());
    }

    /**
     * Determine if the given attribute is hidden.
     */
    protected function attributeIsHidden(string $attribute, Model $model): bool
    {
        if (count($model->getHidden()) > 0) {
            return in_array($attribute, $model->getHidden());
        }

        if (count($model->getVisible()) > 0) {
            return ! in_array($attribute, $model->getVisible());
        }

        return false;
    }

    /**
     * Get the default value for the given column.
     */
    protected function getColumnDefault(array $column, Model $model): mixed
    {
        $attributeDefault = $model->getAttributes()[$column['name']] ?? null;

        return enum_value($attributeDefault) ?? $column['default'];
    }

    /**
     * Determine if the given attribute is unique.
     */
    protected function columnIsUnique(string $column, array $indexes): bool
    {
        return (new BaseCollection($indexes))->contains(
            fn ($index) => count($index['columns']) === 1 && $index['columns'][0] === $column && $index['unique']
        );
    }
}
