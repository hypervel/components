<?php

declare(strict_types=1);

namespace Hypervel\Router\Contracts;

use Hyperf\Database\Model\Model;

interface UrlRoutable
{
    /**
     * Get the value of the model's route key.
     *
     * @return string
     */
    public function getRouteKey();

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName();

    /**
     * Retrieve the model for a bound value.
     *
     * @param string $value
     * @return null|Model
     */
    public function resolveRouteBinding($value);
}
