<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Contracts;

use Hypervel\Container\Contracts\Container;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\HttpMessage\Exceptions\NotFoundHttpException;
use Hypervel\Support\ServiceProvider;
use RuntimeException;

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
    public function detectEnvironment(): string;

    /**
     * Determine if the application is running unit tests.
     */
    public function runningUnitTests(): bool;

    /**
     * Determine if the application is running with debug mode enabled.
     */
    public function hasDebugModeEnabled(): bool;

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
     * Get the application namespace.
     *
     * @throws RuntimeException
     */
    public function getNamespace(): string;
}
