<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

trait HasRelations
{
    /**
     * Duplicate the instance and unset all the loaded relations.
     */
    public function withoutRelations(): static
    {
        $model = clone $this;

        /* @phpstan-ignore-next-line */
        return $model->unsetRelations();
    }
}
