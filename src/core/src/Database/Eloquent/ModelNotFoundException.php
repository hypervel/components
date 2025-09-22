<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Database\Model\ModelNotFoundException as BaseModelNotFoundException;

/**
 * @template TModel of \Hypervel\Database\Eloquent\Model
 */
class ModelNotFoundException extends BaseModelNotFoundException
{
    /**
     * Get the model type.
     *
     * @return null|class-string<TModel>
     */
    public function getModel(): ?string
    {
        return parent::getModel();
    }

    /**
     * Get the model ids.
     *
     * @return array<int, int|string>
     */
    public function getIds(): array
    {
        return parent::getIds();
    }
}
