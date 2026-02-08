<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Database\RecordsNotFoundException;
use Hypervel\Support\Arr;

/**
 * @template TModel of \Hypervel\Database\Eloquent\Model
 */
class ModelNotFoundException extends RecordsNotFoundException
{
    /**
     * Name of the affected Eloquent model.
     *
     * @var class-string<TModel>
     */
    protected string $model;

    /**
     * The affected model IDs.
     *
     * @var array<int, int|string>
     */
    protected array $ids = [];

    /**
     * Set the affected Eloquent model and instance ids.
     *
     * @param class-string<TModel> $model
     * @param array<int, int|string>|int|string $ids
     */
    public function setModel(string $model, array|int|string $ids = []): static
    {
        $this->model = $model;
        $this->ids = Arr::wrap($ids);

        $this->message = "No query results for model [{$model}]";

        if (count($this->ids) > 0) {
            $this->message .= ' ' . implode(', ', $this->ids);
        } else {
            $this->message .= '.';
        }

        return $this;
    }

    /**
     * Get the affected Eloquent model.
     *
     * @return class-string<TModel>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the affected Eloquent model IDs.
     *
     * @return array<int, int|string>
     */
    public function getIds(): array
    {
        return $this->ids;
    }
}
