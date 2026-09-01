<?php

declare(strict_types=1);

namespace Hypervel\Data\Normalizers\Normalized;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\StrCache;

class NormalizedModel implements Normalized
{
    /** @var array<string, mixed> */
    protected array $properties = [];

    /**
     * Create a normalized model source.
     */
    public function __construct(
        protected readonly Model $model,
    ) {
    }

    /**
     * Get one declared property without serializing the model.
     */
    public function getProperty(string $name, DataProperty $dataProperty): mixed
    {
        $propertyName = $this->model::$snakeAttributes ? StrCache::snake($name) : $name;

        return array_key_exists($propertyName, $this->properties)
            ? $this->properties[$propertyName]
            : $this->fetchNewProperty($propertyName, $dataProperty);
    }

    /**
     * Read and memoize one model attribute or relation.
     */
    protected function fetchNewProperty(string $name, DataProperty $dataProperty): mixed
    {
        $camelName = StrCache::camel($name);

        if ($dataProperty->loadRelation) {
            $relation = $this->model->isRelation($name)
                ? $name
                : ($this->model->isRelation($camelName) ? $camelName : null);

            if ($relation !== null) {
                $this->model->loadMissing($relation);
            }
        }

        if ($this->model->relationLoaded($name)) {
            return $this->properties[$name] = $this->model->getRelation($name);
        }

        if ($this->model->relationLoaded($camelName)) {
            return $this->properties[$name] = $this->model->getRelation($camelName);
        }

        if ($this->model->hasAttribute($name)) {
            return $this->properties[$name] = $this->model->getAttribute($name);
        }

        return $this->properties[$name] = UnknownProperty::create();
    }
}
