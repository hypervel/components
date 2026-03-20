<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Support\Arr;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Workbench\Workbench;

/**
 * @internal
 */
trait InteractsWithWorkbench
{
    use InteractsWithPest;
    use InteractsWithPHPUnit;
    use InteractsWithTestCase;

    /**
     * Get Application's base path.
     *
     * @internal
     */
    public static function applicationBasePathUsingWorkbench(): ?string
    {
        return $_ENV['APP_BASE_PATH'] ?? $_ENV['TESTBENCH_APP_BASE_PATH'] ?? null;
    }

    /**
     * Ignore package discovery from.
     *
     * @internal
     *
     * @return null|array<int, string>
     */
    public function ignorePackageDiscoveriesFromUsingWorkbench(): ?array
    {
        if (property_exists($this, 'enablesPackageDiscoveries') && \is_bool($this->enablesPackageDiscoveries)) {
            return $this->enablesPackageDiscoveries === false ? ['*'] : [];
        }

        return static::usesTestingConcern(WithWorkbench::class)
            ? static::cachedConfigurationForWorkbench()?->getExtraAttributes()['dont-discover'] ?? []
            : null;
    }

    /**
     * Get package bootstrapper.
     *
     * @internal
     *
     * @return null|array<int, class-string>
     */
    protected function getPackageBootstrappersUsingWorkbench(object $app): ?array
    {
        if (empty($bootstrappers = static::cachedConfigurationForWorkbench()?->getExtraAttributes()['bootstrappers'] ?? null)) {
            return null;
        }

        return static::usesTestingConcern(WithWorkbench::class)
            ? Arr::wrap($bootstrappers)
            : [];
    }

    /**
     * Get package providers.
     *
     * @internal
     *
     * @return null|array<int, class-string<\Hypervel\Support\ServiceProvider>>
     */
    protected function getPackageProvidersUsingWorkbench(object $app): ?array
    {
        $config = static::cachedConfigurationForWorkbench();

        $hasAuthentication = $config?->getWorkbenchAttributes()['auth'] ?? false;
        $providers = $config?->getExtraAttributes()['providers'] ?? [];

        if ($hasAuthentication === true
            && class_exists(\Hypervel\Auth\AuthServiceProvider::class)
            && ! in_array(\Hypervel\Auth\AuthServiceProvider::class, $providers, true)) {
            $providers[] = \Hypervel\Auth\AuthServiceProvider::class;
        }

        if (empty($providers)) {
            return null;
        }

        return static::usesTestingConcern(WithWorkbench::class)
            ? Arr::wrap($providers)
            : [];
    }

    /**
     * Resolve application Console Kernel implementation.
     *
     * @internal
     */
    protected function applicationConsoleKernelUsingWorkbench(object $app): string
    {
        if (static::usesTestingConcern(WithWorkbench::class)) {
            return Workbench::applicationConsoleKernel() ?? \Hypervel\Testbench\Console\Kernel::class;
        }

        return \Hypervel\Testbench\Console\Kernel::class;
    }

    /**
     * Get application HTTP Kernel implementation using Workbench.
     *
     * @internal
     */
    protected function applicationHttpKernelUsingWorkbench(object $app): string
    {
        if (static::usesTestingConcern(WithWorkbench::class)) {
            return Workbench::applicationHttpKernel() ?? \Hypervel\Testbench\Http\Kernel::class;
        }

        return \Hypervel\Testbench\Http\Kernel::class;
    }

    /**
     * Get application HTTP exception handler using Workbench.
     *
     * @internal
     */
    protected function applicationExceptionHandlerUsingWorkbench(object $app): string
    {
        if (static::usesTestingConcern(WithWorkbench::class)) {
            return Workbench::applicationExceptionHandler() ?? \Hypervel\Testbench\Exceptions\Handler::class;
        }

        return \Hypervel\Testbench\Exceptions\Handler::class;
    }

    /**
     * Define or get the cached uses for test case.
     */
    public static function cachedConfigurationForWorkbench(): ?ConfigContract
    {
        return Workbench::configuration();
    }

    /**
     * Prepare the testing environment before the running the test case.
     *
     * @internal
     *
     * @codeCoverageIgnore
     */
    public static function setUpBeforeClassUsingWorkbench(): void
    {
        $config = static::cachedConfigurationForWorkbench();

        if (
            $config instanceof ConfigContract
            && is_string($config['hypervel'] ?? null)
            && static::usesTestingConcern(WithWorkbench::class)
        ) {
            $_ENV['TESTBENCH_APP_BASE_PATH'] = $config['hypervel'];
        }
    }

    /**
     * Clean up the testing environment before the next test case.
     *
     * @internal
     *
     * @codeCoverageIgnore
     */
    public static function tearDownAfterClassUsingWorkbench(): void
    {
        unset($_ENV['TESTBENCH_APP_BASE_PATH']);
    }
}
