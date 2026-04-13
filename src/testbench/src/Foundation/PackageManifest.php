<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest as FoundationPackageManifest;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Override;

use function Hypervel\Testbench\is_testbench_cli;
use function Hypervel\Testbench\package_path;

/**
 * @api
 */
class PackageManifest extends FoundationPackageManifest
{
    /**
     * The current testbench instance.
     */
    protected ?object $testbench = null;

    /**
     * Packages that must remain discoverable when discovery is disabled.
     *
     * @var array<int, string>
     */
    protected array $requiredPackages = [];

    /**
     * Create a new package manifest instance.
     */
    public function __construct(
        Filesystem $files,
        string $basePath,
        ?string $manifestPath,
        ?object $testbench = null
    ) {
        parent::__construct($files, $basePath, $manifestPath);

        $this->setTestbench($testbench);
    }

    /**
     * Swap the application binding to the Testbench package manifest.
     */
    public static function swap(ApplicationContract $app, ?object $testbench = null): void
    {
        /** @var FoundationPackageManifest $base */
        $base = $app->make(FoundationPackageManifest::class);

        FoundationPackageManifest::ignorePackageDiscoveriesFrom([]);

        $manifest = new static(
            $base->files,
            $base->basePath,
            $base->manifestPath,
            $testbench
        );

        $app->instance(FoundationPackageManifest::class, $manifest);
        $app->instance(self::class, $manifest);
    }

    /**
     * Set the current testbench instance.
     */
    public function setTestbench(?object $testbench): void
    {
        $this->testbench = $testbench;
    }

    /**
     * Require packages even when discovery is disabled.
     */
    public function requires(string ...$packages): static
    {
        $this->requiredPackages = array_values(array_unique(
            array_merge($this->requiredPackages, Arr::wrap($packages))
        ));

        return $this;
    }

    /**
     * Get the current package manifest.
     */
    #[Override]
    protected function getManifest(): array
    {
        $ignore = $this->testbench !== null && method_exists($this->testbench, 'ignorePackageDiscoveriesFrom')
            ? ($this->testbench->ignorePackageDiscoveriesFrom() ?? [])
            : [];

        $ignoreAll = in_array('*', $ignore, true);
        $requiredPackages = $this->requiredPackages;

        return (new Collection(parent::getManifest()))
            ->reject(static fn (array $configuration, string $package): bool => ($ignoreAll && ! in_array($package, $requiredPackages, true)) || in_array($package, $ignore, true))
            ->map(static function (array $configuration): ?array {
                foreach ($configuration['providers'] ?? [] as $provider) {
                    if (! class_exists($provider)) {
                        return null;
                    }
                }

                return $configuration;
            })
            ->filter()
            ->all();
    }

    /**
     * Get the packages that should be ignored during manifest build.
     *
     * @return array<int, string>
     */
    #[Override]
    protected function packagesToIgnore(): array
    {
        return [];
    }

    /**
     * Get the root package discovery configuration.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function providersFromRoot(): array
    {
        $package = $this->providersFromTestbench();

        if (! is_array($package)) {
            return [];
        }

        return [
            $this->format($package['name']) => $package['extra']['hypervel'] ?? [],
        ];
    }

    /**
     * Get the root package composer metadata.
     *
     * @return null|array{name: string, extra?: array{hypervel?: array<string, mixed>}}
     */
    protected function providersFromTestbench(): ?array
    {
        if (is_testbench_cli() && is_file($composerFile = package_path('composer.json'))) {
            return $this->files->json($composerFile);
        }

        return null;
    }

    /**
     * Write the given manifest array to disk.
     */
    #[Override]
    protected function write(array $manifest): void
    {
        parent::write(
            (new Collection($manifest))
                ->merge($this->providersFromRoot())
                ->filter()
                ->all()
        );
    }
}
