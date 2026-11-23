<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use function array_keys;
use function fnmatch;
use function getenv;
use function hash;
use function implode;
use function is_bool;
use function is_scalar;
use function json_encode;
use function ksort;

use const JSON_THROW_ON_ERROR;

/**
 * The environment variables as the process received them, before the application boots.
 *
 * Booting loads the environment file into $_SERVER, $_ENV and putenv(). PHPStan compares
 * cached values in the main process before anything boots, and records new ones in workers
 * after they boot, so both must read the variables as they were before booting.
 *
 * @internal
 */
class EnvironmentSnapshot
{
    /**
     * The variables captured before boot, as each default environment adapter reads them.
     *
     * @var null|array{server: array<array-key, mixed>, env: array<array-key, mixed>, process: array<string, string>}
     */
    protected static ?array $captured = null;

    /**
     * Capture the variables before the application boots.
     */
    public static function capture(): void
    {
        static::$captured ??= ['server' => $_SERVER, 'env' => $_ENV, 'process' => getenv()];
    }

    /**
     * Get the value of a variable as the environment repository reads it, or null when it is not set.
     */
    public static function get(string $name): ?string
    {
        [$server, $env, $process] = static::variables();

        return static::scalar($server, $name) ?? static::scalar($env, $name) ?? $process[$name] ?? null;
    }

    /**
     * Get a digest of the named variables and of every variable matching one of the patterns.
     *
     * Each variable is digested as each default adapter reads it (ServerConstAdapter,
     * EnvConstAdapter, PutenvAdapter), keeping an unset variable distinct from an empty one,
     * so the digest changes whichever adapters the application enables.
     *
     * @param list<string> $names
     * @param list<string> $patterns fnmatch() patterns matched against the set variable names
     */
    public static function digest(array $names, array $patterns): string
    {
        [$server, $env, $process] = static::variables();

        $selected = [];

        foreach ($names as $name) {
            $selected[$name] = true;
        }

        if ($patterns !== []) {
            // $_SERVER also holds PHP's request metadata, such as REQUEST_TIME, which is not
            // environment input; process variables reach it from getenv(). Patterns also skip
            // PHPRC, which PHPStan's restart points at a new temporary ini file on every run: it
            // sets PHP up rather than configuring the application. A named read still tracks it.
            foreach (array_keys($process + $env) as $name) {
                if ($name === 'PHPRC') {
                    continue;
                }

                foreach ($patterns as $pattern) {
                    if (fnmatch($pattern, (string) $name)) {
                        $selected[(string) $name] = true;

                        break;
                    }
                }
            }
        }

        ksort($selected);

        $lines = [];

        foreach (array_keys($selected) as $name) {
            $lines[] = $name . '=' . json_encode(
                [static::scalar($server, $name), static::scalar($env, $name), $process[$name] ?? null],
                JSON_THROW_ON_ERROR,
            );
        }

        return hash('xxh128', implode("\n", $lines));
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$captured = null;
    }

    /**
     * Get the captured variables, or the current ones before anything has booted.
     *
     * @return array{array<array-key, mixed>, array<array-key, mixed>, array<string, string>}
     */
    protected static function variables(): array
    {
        if (static::$captured !== null) {
            return [static::$captured['server'], static::$captured['env'], static::$captured['process']];
        }

        return [$_SERVER, $_ENV, getenv()];
    }

    /**
     * Read a variable from a superglobal as dotenv's constant adapters do.
     *
     * @param array<array-key, mixed> $variables
     */
    protected static function scalar(array $variables, string $name): ?string
    {
        $value = $variables[$name] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
