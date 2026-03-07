<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Foundation;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\ServiceProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

interface Application extends Container
{
    /**
     * Get the version number of the application.
     */
    public function version(): string;

    /**
     * Run the given array of bootstrap classes.
     */
    public function bootstrapWith(array $bootstrappers): void;

    /**
     * Determine if the application has been bootstrapped before.
     */
    public function hasBeenBootstrapped(): bool;

    /**
     * Set the base path for the application.
     *
     * @return $this
     */
    public function setBasePath(string $basePath): static;

    /**
     * Get the base path of the Hypervel installation.
     */
    public function basePath(string $path = ''): string;

    /**
     * Get the path to the bootstrap directory.
     */
    public function bootstrapPath(string $path = ''): string;

    /**
     * Get the path to the application "app" directory.
     */
    public function path(string $path = ''): string;

    /**
     * Get the path to the application configuration files.
     */
    public function configPath(string $path = ''): string;

    /**
     * Get the path to the database directory.
     */
    public function databasePath(string $path = ''): string;

    /**
     * Get the path to the language files.
     */
    public function langPath(string $path = ''): string;

    /**
     * Get the path to the public directory.
     */
    public function publicPath(string $path = ''): string;

    /**
     * Get the path to the resources directory.
     */
    public function resourcePath(string $path = ''): string;

    /**
     * Get the path to the views directory.
     *
     * This method returns the first configured path in the array of view paths.
     */
    public function viewPath(string $path = ''): string;

    /**
     * Get the path to the storage directory.
     */
    public function storagePath(string $path = ''): string;

    /**
     * Get the path to the configuration cache file.
     */
    public function getCachedConfigPath(): string;

    /**
     * Join the given paths together.
     */
    public function joinPaths(string $basePath, string $path = ''): string;

    /**
     * Get or check the current application environment.
     *
     * @param array|string ...$environments
     */
    public function environment(...$environments): bool|string;

    /**
     * Determine if the application is in the local environment.
     */
    public function isLocal(): bool;

    /**
     * Determine if the application is in the production environment.
     */
    public function isProduction(): bool;

    /**
     * Detect the application's current environment.
     */
    public function detectEnvironment(Closure $callback): string;

    /**
     * Set the callback to resolve the application's environment.
     */
    public function resolveEnvironmentUsing(?callable $callback): void;

    /**
     * Get the path to the environment file directory.
     */
    public function environmentPath(): string;

    /**
     * Set the directory for the environment file.
     *
     * @return $this
     */
    public function useEnvironmentPath(string $path): static;

    /**
     * Set the environment file to be loaded during bootstrapping.
     *
     * @return $this
     */
    public function loadEnvironmentFrom(string $file): static;

    /**
     * Get the environment file the application is using.
     */
    public function environmentFile(): string;

    /**
     * Get the fully qualified path to the environment file.
     */
    public function environmentFilePath(): string;

    /**
     * Determine if the application is running in the console.
     *
     * In Swoole, PHP_SAPI is always 'cli', so the value is determined by
     * explicit framework signals rather than SAPI detection.
     */
    public function runningInConsole(): bool;

    /**
     * Determine if the application is running any of the given console commands.
     */
    public function runningConsoleCommand(string|array ...$commands): bool;

    /**
     * Determine if the application is running unit tests.
     */
    public function runningUnitTests(): bool;

    /**
     * Determine if the application is running with debug mode enabled.
     */
    public function hasDebugModeEnabled(): bool;

    /**
     * Determine if the application is currently down for maintenance.
     */
    public function isDownForMaintenance(): bool;

    /**
     * Register all of the configured providers.
     */
    public function registerConfiguredProviders(): void;

    /**
     * Register a service provider with the application.
     */
    public function register(ServiceProvider|string $provider, bool $force = false): ServiceProvider;

    /**
     * Get the registered service provider instances if any exist.
     */
    public function getProviders(ServiceProvider|string $provider): array;

    /**
     * Resolve a service provider instance from the class name.
     */
    public function resolveProvider(string $provider): ServiceProvider;

    /**
     * Determine if the application has booted.
     */
    public function isBooted(): bool;

    /**
     * Boot the application's service providers.
     */
    public function boot(): void;

    /**
     * Register a new boot listener.
     */
    public function booting(callable $callback): void;

    /**
     * Register a new "booted" listener.
     */
    public function booted(callable $callback): void;

    /**
     * Throw an HttpException with the given data.
     *
     * @throws HttpException
     * @throws NotFoundHttpException
     */
    public function abort(int $code, string $message = '', array $headers = []): void;

    /**
     * Get the service providers that have been loaded.
     *
     * @return array<string, bool>
     */
    public function getLoadedProviders(): array;

    /**
     * Determine if the given service provider is loaded.
     */
    public function providerIsLoaded(string $provider): bool;

    /**
     * Get the current application locale.
     */
    public function getLocale(): string;

    /**
     * Determine if the application locale is the given locale.
     */
    public function isLocale(string $locale): bool;

    /**
     * Get the current application locale.
     */
    public function currentLocale(): string;

    /**
     * Get the current application fallback locale.
     */
    public function getFallbackLocale(): string;

    /**
     * Set the current application locale.
     */
    public function setLocale(string $locale): void;

    /**
     * Determine if the application routes are cached.
     */
    public function routesAreCached(): bool;

    /**
     * Get the path to the routes cache file.
     */
    public function getCachedRoutesPath(): string;

    /**
     * Determine if middleware has been disabled for the application.
     */
    public function shouldSkipMiddleware(): bool;

    /**
     * Register a terminating callback with the application.
     *
     * @return $this
     */
    public function terminating(callable|string $callback): static;

    /**
     * Terminate the application.
     */
    public function terminate(): void;

    /**
     * Get the application namespace.
     *
     * @throws RuntimeException
     */
    public function getNamespace(): string;
}
