<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Concerns;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Support\Str;

trait InteractsWithModels
{
    /**
     * Retrieve the model identified by the given input.
     *
     * @template TModel of Model
     *
     * @param null|class-string<TModel>|Closure $modelClass
     * @param null|(Closure(Builder<TModel>): mixed) $callback
     * @return null|TModel
     */
    public function model(string $key, Closure|string|null $modelClass = null, ?Closure $callback = null): ?Model
    {
        if (is_null($id = $this->modelIdentifier($key))) {
            return null;
        }

        return $this->newModelQuery($key, $modelClass, $callback)
            ->whereKey($id)
            ->first();
    }

    /**
     * Retrieve the model identified by the given input or throw an exception.
     *
     * @template TModel of Model
     *
     * @param null|class-string<TModel>|Closure $modelClass
     * @param null|(Closure(Builder<TModel>): mixed) $callback
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    public function modelOrFail(string $key, Closure|string|null $modelClass = null, ?Closure $callback = null): Model
    {
        return $this->newModelQuery($key, $modelClass, $callback)
            ->whereKey($this->modelIdentifier($key))
            ->firstOrFail();
    }

    /**
     * Retrieve the models identified by the given input.
     *
     * @template TModel of Model
     *
     * @param null|class-string<TModel>|Closure $modelClass
     * @param null|(Closure(Builder<TModel>): mixed) $callback
     * @return Collection<int, TModel>
     */
    public function models(string $key, Closure|string|null $modelClass = null, ?Closure $callback = null): Collection
    {
        return $this->newModelQuery($key, $modelClass, $callback)
            ->findMany($this->modelIdentifiers($key));
    }

    /**
     * Get the single model identifier from the given input.
     */
    protected function modelIdentifier(string $key): mixed
    {
        $id = $this->input($key);

        return $this->isNotFilled($key) || is_array($id) ? null : $id;
    }

    /**
     * Get the model identifiers from the given input.
     *
     * @return list<mixed>
     */
    protected function modelIdentifiers(string $key): array
    {
        return array_values(array_filter(
            $this->array($key),
            fn (mixed $id): bool => ! is_array($id)
        ));
    }

    /**
     * Create a model query for the given input key.
     *
     * @template TModel of Model
     *
     * @param null|class-string<TModel>|Closure $modelClass
     * @param null|(Closure(Builder<TModel>): mixed) $callback
     * @return Builder<TModel>
     */
    protected function newModelQuery(string $key, Closure|string|null $modelClass = null, ?Closure $callback = null): Builder
    {
        if ($modelClass instanceof Closure) {
            $callback = $modelClass;
            $modelClass = null;
        }

        $modelClass ??= $this->guessModelClass($key);

        $query = $modelClass::query();

        if ($callback) {
            $query->where($callback);
        }

        // @phpstan-ignore return.type (Without a model class, TModel resolves to its Model bound, which the guessed model class satisfies.)
        return $query;
    }

    /**
     * Guess the model class for the given input key.
     *
     * @return class-string<Model>
     */
    protected function guessModelClass(string $key): string
    {
        $name = Str::studly(Str::singular(Str::chopEnd($key, ['_ids', '_id'])));

        $namespace = $this->container->make(Application::class)->getNamespace();

        return class_exists($namespace . 'Models\\' . $name)
            ? $namespace . 'Models\\' . $name
            : $namespace . $name;
    }
}
