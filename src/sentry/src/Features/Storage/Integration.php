<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Storage;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Filesystem\Cloud as CloudFilesystem;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Filesystem\AwsS3V3Adapter;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Sentry\Features\Feature;
use RuntimeException;

class Integration extends Feature
{
    private const string FEATURE_KEY = 'storage';

    private const string STORAGE_DRIVER_NAME = 'sentry';

    public function isApplicable(): bool
    {
        // The driver must remain available for per-disk overrides even when the
        // global Storage feature flags cannot currently produce telemetry.
        return true;
    }

    /**
     * Register the Sentry filesystem driver.
     */
    public function register(): void
    {
        $this->container->afterResolving(FilesystemManager::class, function (FilesystemManager $filesystemManager): void {
            // Store constants and default feature flags in local variables because `FilesystemManager::extend()`
            // re-binds the closure scope to `FilesystemManager` which causes `self::` and `$this` to resolve
            // on `FilesystemManager` instead of the `Integration` class.
            $driverName = self::STORAGE_DRIVER_NAME;
            $canRecordSpans = $this->canRecordSpans();
            $canRecordBreadcrumbs = $this->canRecordBreadcrumbs();
            $defaultRecordSpans = $this->isTracingFeatureEnabled(self::FEATURE_KEY);
            $defaultRecordBreadcrumbs = $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);

            $filesystemManager->extend(
                $driverName,
                function (Container $application, array $config, ?string $name) use ($filesystemManager, $driverName, $canRecordSpans, $canRecordBreadcrumbs, $defaultRecordSpans, $defaultRecordBreadcrumbs): Filesystem {
                    $disk = $name ?? ($config['sentry_disk_name'] ?? null);

                    if (! is_string($disk) || $disk === '') {
                        throw new RuntimeException(sprintf('Missing `sentry_disk_name` config key for `%s` filesystem driver.', $driverName));
                    }

                    if (empty($config['sentry_original_driver'])) {
                        throw new RuntimeException(sprintf('Missing `sentry_original_driver` config key for `%s` filesystem driver.', $driverName));
                    }

                    if ($config['sentry_original_driver'] === $driverName) {
                        throw new RuntimeException(sprintf('`sentry_original_driver` for Sentry storage integration cannot be the `%s` driver.', $driverName));
                    }

                    $config['driver'] = $config['sentry_original_driver'];
                    unset($config['sentry_original_driver']);

                    $originalFilesystem = $filesystemManager->build($config, $disk);

                    if ($originalFilesystem instanceof DecoratedFilesystem) {
                        $originalFilesystem = $originalFilesystem->getFilesystem();
                    }

                    $defaultData = ['disk' => $disk, 'driver' => $config['driver']];

                    $recordSpans = $canRecordSpans
                        && ($config['sentry_enable_spans'] ?? $defaultRecordSpans) === true;
                    $recordBreadcrumbs = $canRecordBreadcrumbs
                        && ($config['sentry_enable_breadcrumbs'] ?? $defaultRecordBreadcrumbs) === true;

                    if (! $recordSpans && ! $recordBreadcrumbs) {
                        return $originalFilesystem;
                    }

                    if ($originalFilesystem instanceof AwsS3V3Adapter) {
                        return new SentryS3V3Adapter($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs);
                    }

                    if ($originalFilesystem instanceof FilesystemAdapter) {
                        return new SentryFilesystemAdapter($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs);
                    }

                    if ($originalFilesystem instanceof CloudFilesystem) {
                        return new SentryCloudFilesystem($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs);
                    }

                    return new SentryFilesystem($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs);
                }
            );
        });
    }

    /**
     * Decorate the configuration for a single disk with Sentry driver configuration.
     *
     * This replaces the driver with a custom driver that will capture performance traces and breadcrumbs.
     *
     * The custom driver will be an instance of @see SentryS3V3Adapter if the original driver
     * is an @see AwsS3V3Adapter, and an instance of @see SentryFilesystemAdapter if the original
     * driver is an @see FilesystemAdapter. If the original driver is neither of those, it will
     * be @see SentryFilesystem or @see SentryCloudFilesystem based on the original contract.
     *
     * You might run into problems if you expect another specific driver class.
     *
     * @param array<string, mixed> $diskConfig
     *
     * @return array<string, mixed>
     */
    public static function configureDisk(string $diskName, array $diskConfig, bool $enableSpans = true, bool $enableBreadcrumbs = true): array
    {
        $currentDriver = $diskConfig['driver'];

        if ($currentDriver !== self::STORAGE_DRIVER_NAME) {
            $diskConfig['driver'] = self::STORAGE_DRIVER_NAME;
            $diskConfig['sentry_disk_name'] = $diskName;
            $diskConfig['sentry_original_driver'] = $currentDriver;
            $diskConfig['sentry_enable_spans'] = $enableSpans;
            $diskConfig['sentry_enable_breadcrumbs'] = $enableBreadcrumbs;
        }

        return $diskConfig;
    }

    /**
     * Decorate the configuration for all disks with Sentry driver configuration.
     *
     * @see self::configureDisk()
     *
     * @param array<string, array<string, mixed>> $diskConfigs
     *
     * @return array<string, array<string, mixed>>
     */
    public static function configureDisks(array $diskConfigs, bool $enableSpans = true, bool $enableBreadcrumbs = true): array
    {
        $diskConfigsWithSentryDriver = [];
        foreach ($diskConfigs as $diskName => $diskConfig) {
            $diskConfigsWithSentryDriver[$diskName] = static::configureDisk($diskName, $diskConfig, $enableSpans, $enableBreadcrumbs);
        }

        return $diskConfigsWithSentryDriver;
    }
}
