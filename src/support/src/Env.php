<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Dotenv\Repository\Adapter\AdapterInterface;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\RepositoryInterface;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use PhpOption\Option;
use RuntimeException;

class Env
{
    /**
     * Indicates if the putenv adapter is enabled.
     */
    protected static bool $putenv = true;

    /**
     * The environment repository instance.
     */
    protected static ?RepositoryInterface $repository = null;

    /**
     * The adapters used to build the current repository.
     *
     * Stored so that deleteMany() can remove values from all adapters
     * without leaking adapter construction details to external callers.
     *
     * @var AdapterInterface[]
     */
    protected static array $adapters = [];

    /**
     * The list of custom adapters for loading environment variables.
     *
     * @var array<Closure>
     */
    protected static array $customAdapters = [];

    /**
     * Enable the putenv adapter.
     */
    public static function enablePutenv(): void
    {
        static::$putenv = true;
        static::$repository = null;
    }

    /**
     * Reset the environment repository.
     *
     * Clears the cached repository so the next getRepository() call creates
     * a fresh instance with a new ImmutableWriter. This is required before
     * reloading env files — the ImmutableWriter tracks which keys it loaded
     * and refuses to overwrite "externally defined" keys. Resetting creates
     * a clean writer that treats all keys as writable.
     */
    public static function resetRepository(): void
    {
        static::$repository = null;
        static::$adapters = [];
    }

    /**
     * Reset all static state including custom adapters and putenv config.
     *
     * This is a full teardown intended for testing. Unlike resetRepository(),
     * which preserves custom adapters and putenv configuration, this method
     * restores Env to its initial state.
     */
    public static function flushState(): void
    {
        static::$putenv = true;
        static::$repository = null;
        static::$adapters = [];
        static::$customAdapters = [];
    }

    /**
     * Delete the given environment variable keys from all adapters.
     *
     * Bypasses the repository's ImmutableWriter and deletes directly
     * from each adapter. This is used during env reload to clear
     * previously loaded values before re-reading the env file.
     *
     * Also clears the keys from the default adapter superglobals
     * ($_SERVER, $_ENV) which are always present via createWithDefaultAdapters().
     *
     * @param string[] $keys
     */
    public static function deleteMany(array $keys): void
    {
        foreach ($keys as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }

        foreach (static::$adapters as $adapter) {
            foreach ($keys as $key) {
                $adapter->delete($key);
            }
        }
    }

    /**
     * Disable the putenv adapter.
     */
    public static function disablePutenv(): void
    {
        static::$putenv = false;
        static::$repository = null;
    }

    /**
     * Register a custom adapter creator Closure.
     */
    public static function extend(Closure $callback, ?string $name = null): void
    {
        if (! is_null($name)) {
            static::$customAdapters[$name] = $callback;
        } else {
            static::$customAdapters[] = $callback;
        }

        static::$repository = null;
    }

    /**
     * Get the environment repository instance.
     */
    public static function getRepository(): RepositoryInterface
    {
        if (static::$repository === null) {
            $adapters = static::buildAdapters();
            $builder = RepositoryBuilder::createWithDefaultAdapters();

            foreach ($adapters as $adapter) {
                $builder = $builder->addAdapter($adapter);
            }

            static::$adapters = $adapters;
            static::$repository = $builder->immutable()->make();
        }

        return static::$repository;
    }

    /**
     * Build the list of additional adapters for the repository.
     *
     * @return AdapterInterface[]
     */
    protected static function buildAdapters(): array
    {
        $adapters = [];

        if (static::$putenv) {
            $adapter = PutenvAdapter::create();
            if ($adapter->isDefined()) {
                $adapters[] = $adapter->get();
            }
        }

        foreach (static::$customAdapters as $callback) {
            $result = $callback();

            // Callbacks may return an adapter instance or a class-string
            // (same as RepositoryBuilder::addAdapter accepts). Resolve
            // class-strings to instances so deleteMany() can call ->delete().
            if (is_string($result)) {
                $instance = $result::create();
                if ($instance->isDefined()) {
                    $adapters[] = $instance->get();
                }
            } else {
                $adapters[] = $result;
            }
        }

        return $adapters;
    }

    /**
     * Get the value of an environment variable.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::getOption($key)->getOrCall(fn () => value($default));
    }

    /**
     * Get the value of a required environment variable.
     *
     * @throws RuntimeException
     */
    public static function getOrFail(string $key): mixed
    {
        return self::getOption($key)->getOrThrow(new RuntimeException("Environment variable [{$key}] has no value."));
    }

    /**
     * Write an array of key-value pairs to the environment file.
     *
     * @param array<string, mixed> $variables
     *
     * @throws RuntimeException
     * @throws FileNotFoundException
     */
    public static function writeVariables(array $variables, string $pathToFile, bool $overwrite = false): void
    {
        $filesystem = new Filesystem();

        if ($filesystem->missing($pathToFile)) {
            throw new RuntimeException("The file [{$pathToFile}] does not exist.");
        }

        $lines = explode(PHP_EOL, $filesystem->get($pathToFile));

        foreach ($variables as $key => $value) {
            $lines = self::addVariableToEnvContents($key, $value, $lines, $overwrite);
        }

        $filesystem->put($pathToFile, implode(PHP_EOL, $lines));
    }

    /**
     * Write a single key-value pair to the environment file.
     *
     * @throws RuntimeException
     * @throws FileNotFoundException
     */
    public static function writeVariable(string $key, mixed $value, string $pathToFile, bool $overwrite = false): void
    {
        $filesystem = new Filesystem();

        if ($filesystem->missing($pathToFile)) {
            throw new RuntimeException("The file [{$pathToFile}] does not exist.");
        }

        $envContent = $filesystem->get($pathToFile);

        $lines = explode(PHP_EOL, $envContent);
        $lines = self::addVariableToEnvContents($key, $value, $lines, $overwrite);

        $filesystem->put($pathToFile, implode(PHP_EOL, $lines));
    }

    /**
     * Add a variable to the environment file contents.
     *
     * @param array<int, string> $envLines
     * @return array<int, string>
     */
    protected static function addVariableToEnvContents(string $key, mixed $value, array $envLines, bool $overwrite): array
    {
        $prefix = explode('_', $key)[0] . '_';
        $lastPrefixIndex = -1;

        $shouldQuote = preg_match('/^[a-zA-z0-9]+$/', $value) === 0;

        $lineToAddVariations = [
            $key . '=' . (is_string($value) ? self::prepareQuotedValue($value) : $value),
            $key . '=' . $value,
        ];

        $lineToAdd = $shouldQuote ? $lineToAddVariations[0] : $lineToAddVariations[1];

        if ($value === '') {
            $lineToAdd = $key . '=';
        }

        foreach ($envLines as $index => $line) {
            if (str_starts_with($line, $prefix)) {
                $lastPrefixIndex = $index;
            }

            if (in_array($line, $lineToAddVariations)) {
                // This exact line already exists, so we don't need to add it again.
                return $envLines;
            }

            if ($line === $key . '=') {
                // If the value is empty, we can replace it with the new value.
                $envLines[$index] = $lineToAdd;

                return $envLines;
            }

            if (str_starts_with($line, $key . '=')) {
                if (! $overwrite) {
                    return $envLines;
                }

                $envLines[$index] = $lineToAdd;

                return $envLines;
            }
        }

        if ($lastPrefixIndex === -1) {
            if (count($envLines) && $envLines[count($envLines) - 1] !== '') {
                $envLines[] = '';
            }

            return array_merge($envLines, [$lineToAdd]);
        }

        return array_merge(
            array_slice($envLines, 0, $lastPrefixIndex + 1),
            [$lineToAdd],
            array_slice($envLines, $lastPrefixIndex + 1)
        );
    }

    /**
     * Get the possible option for this environment variable.
     */
    protected static function getOption(string $key): Option
    {
        return Option::fromValue(static::getRepository()->get($key))
            ->map(function ($value) {
                switch (strtolower($value)) {
                    case 'true':
                    case '(true)':
                        return true;
                    case 'false':
                    case '(false)':
                        return false;
                    case 'empty':
                    case '(empty)':
                        return '';
                    case 'null':
                    case '(null)':
                        return;
                }

                if (preg_match('/\A([\'"])(.*)\1\z/', $value, $matches)) {
                    return $matches[2];
                }

                return $value;
            });
    }

    /**
     * Wrap a string in quotes, choosing double or single quotes.
     */
    protected static function prepareQuotedValue(string $input): string
    {
        return str_contains($input, '"')
            ? "'" . self::addSlashesExceptFor($input, ['"']) . "'"
            : '"' . self::addSlashesExceptFor($input, ["'"]) . '"';
    }

    /**
     * Escape a string using addslashes, excluding the specified characters from being escaped.
     *
     * @param array<int, string> $except
     */
    protected static function addSlashesExceptFor(string $value, array $except = []): string
    {
        $escaped = addslashes($value);

        foreach ($except as $character) {
            $escaped = str_replace('\\' . $character, $character, $escaped);
        }

        return $escaped;
    }
}
