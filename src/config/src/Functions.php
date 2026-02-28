<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hypervel\Container\Container;

/**
 * Get / set the specified configuration value.
 *
 * If an array is passed as the key, we will assume you want to set an array of values.
 *
 * @param null|array<string, mixed>|string $key
 * @return ($key is null ? \Hypervel\Contracts\Config\Repository : ($key is string ? mixed : null))
 */
function config(mixed $key = null, mixed $default = null): mixed
{
    $config = Container::getInstance()->make('config');

    if (is_null($key)) {
        return $config;
    }

    if (is_array($key)) {
        return $config->set($key);
    }

    return $config->get($key, $default);
}
