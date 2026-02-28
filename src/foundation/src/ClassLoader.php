<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hypervel\Support\DotenvManager;

class ClassLoader
{
    protected static ?string $configDir = null;

    /**
     * Initialize the class loader.
     */
    public static function init(?string $configDir = null): void
    {
        static::$configDir = $configDir ?? BASE_PATH . '/config/';

        static::loadEnv();
    }

    protected static function loadEnv(): void
    {
        if (! file_exists(BASE_PATH . '/.env')) {
            return;
        }

        DotenvManager::load([BASE_PATH]);
    }
}
