<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Arr;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Workbench\Workbench;
use Hypervel\Workbench\AuthServiceProvider;

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
        return $_ENV['APP_BASE_PATH'] ?? null;
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
    protected function getPackageBootstrappersUsingWorkbench(ApplicationContract $app): ?array
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
     * @return null|array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProvidersUsingWorkbench(ApplicationContract $app): ?array
    {
        $config = static::cachedConfigurationForWorkbench();

        $hasAuthentication = $config?->getWorkbenchAttributes()['auth'] ?? false;
        $providers = $config?->getExtraAttributes()['providers'] ?? [];

        if ($hasAuthentication === true
            && class_exists(AuthServiceProvider::class)
            && ! in_array(AuthServiceProvider::class, $providers, true)) {
            $providers[] = AuthServiceProvider::class;
        }

        if (empty($providers)) {
            return null;
        }

        return static::usesTestingConcern(WithWorkbench::class) || ! static::usesTestingConcern()
            ? Arr::wrap($providers)
            : [];
    }

    /**
     * Resolve application Console Kernel implementation.
     *
     * @internal
     */
    protected function applicationConsoleKernelUsingWorkbench(ApplicationContract $app): string
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
    protected function applicationHttpKernelUsingWorkbench(ApplicationContract $app): string
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
    protected function applicationExceptionHandlerUsingWorkbench(ApplicationContract $app): string
    {
        if (static::usesTestingConcern(WithWorkbench::class)) {
            return Workbench::applicationExceptionHandler() ?? \Hypervel\Testbench\Exceptions\Handler::class;
        }

        return \Hypervel\Testbench\Exceptions\Handler::class;
    }

    /**
     * Get the cached Workbench configuration.
     */
    public static function cachedConfigurationForWorkbench(): ?ConfigContract
    {
        return Workbench::configuration();
    }

    // Orchestra sets a per-class app-base env pointer for YAML custom skeletons.
    // Hypervel's Bootstrapper already clones the yaml skeleton as each process's
    // runtime identity, and per-class skeleton switching cannot work with
    // process-owned disposable clones.
}
