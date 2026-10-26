<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

use Hypervel\Support\DotenvManager;
use Hypervel\Support\Facades\Process;
use Symfony\Component\Console\Input\ArgvInput;

class EnvironmentConfiguration
{
    /**
     * Refresh a repaired setting from a fresh application and return any failure.
     */
    public static function refresh(string $key, bool|string $expected): ?string
    {
        $cachePath = app()->getCachedConfigPath();
        $arguments = [PHP_BINARY, 'artisan'];

        if (($input = new ArgvInput)->hasParameterOption('--env')) {
            $arguments[] = '--env=' . $input->getParameterOption('--env');
        }

        $process = Process::path(base_path())
            ->env([
                ...array_fill_keys(DotenvManager::getLoadedVariableNames(), false),
                'APP_CONFIG_CACHE' => $cachePath,
            ]);

        if (is_file($cachePath)
            && ! (clone $process)->forever()->run([...$arguments, 'config:cache'])->successful()) {
            return 'The environment file was updated, but the configuration cache could not be rebuilt. Run `php artisan config:cache` to see the error.';
        }

        $result = $process->run([...$arguments, 'config:show', $key, '--json']);

        if (! $result->successful()) {
            return "The environment file was updated, but [{$key}] could not be read from a fresh application. Run `php artisan config:show {$key}` to inspect the configuration.";
        }

        $value = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        config([$key => $value]);

        return $value === $expected
            ? null
            : "The environment file was updated, but [{$key}] still has a different value in a fresh application. Check the application configuration and external environment variables.";
    }
}
