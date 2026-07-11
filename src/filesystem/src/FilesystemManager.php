<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Aws\S3\S3Client;
use Closure;
use Google\Cloud\Storage\StorageClient as GcsClient;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Filesystem\Cloud;
use Hypervel\Contracts\Filesystem\Factory as FactoryContract;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter as AwsS3PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter as GcsAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Contracts\Filesystem\Filesystem
 * @mixin \Hypervel\Filesystem\FilesystemAdapter
 */
class FilesystemManager implements FactoryContract
{
    use HasPoolProxy;

    /**
     * The logical name used while resolving on-demand disks.
     */
    protected const ON_DEMAND_DISK_NAME = 'ondemand';

    /**
     * Google Cloud Storage client constructor options supported by the installed SDK.
     */
    protected const GCS_CLIENT_OPTIONS = [
        'apiEndpoint',
        'projectId',
        'authCache',
        'authCacheOptions',
        'authHttpHandler',
        'credentialsFetcher',
        'httpHandler',
        'keyFile',
        'keyFilePath',
        'requestTimeout',
        'retries',
        'retryStrategy',
        'restDelayFunction',
        'restCalcDelayFunction',
        'restRetryFunction',
        'restRetryListener',
        'scopes',
        'quotaProject',
    ];

    /** @var null|list<string> */
    protected static ?array $s3ArgumentNames = null;

    /**
     * The array of resolved filesystem drivers.
     */
    protected array $disks = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The array of drivers which will be wrapped as pool proxies.
     */
    protected array $poolables = ['s3', 'gcs'];

    /**
     * Create a new filesystem manager instance.
     */
    public function __construct(
        protected Container $app
    ) {
    }

    /**
     * Get a filesystem instance.
     */
    public function drive(UnitEnum|string|null $name = null): mixed
    {
        return $this->disk($name);
    }

    /**
     * Get a filesystem instance.
     */
    public function disk(UnitEnum|string|null $name = null): mixed
    {
        $name = enum_value($name);
        $name = $name === null ? $this->getDefaultDriver() : (string) $name;

        return $this->disks[$name] = $this->get($name);
    }

    // Laravel's cloud() default-cloud shortcut is intentionally not ported.
    // Use named disks via disk('s3'), disk('uploads'), etc.

    /**
     * Build an on-demand disk.
     */
    public function build(array|string $config): mixed
    {
        return $this->resolve(self::ON_DEMAND_DISK_NAME, is_array($config) ? $config : [
            'driver' => 'local',
            'root' => $config,
        ]);
    }

    /**
     * Attempt to get the disk from the local cache.
     */
    protected function get(string $name): mixed
    {
        return $this->disks[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given disk.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name, ?array $config = null): mixed
    {
        $config ??= $this->getConfig($name);

        if (empty($config['driver'])) {
            throw new InvalidArgumentException("Disk [{$name}] does not have a configured driver.");
        }

        $driver = $config['driver'];
        $hasPool = in_array($driver, $this->poolables, true);
        $constructionConfig = Arr::except($config, ['pool']);

        if (isset($this->customCreators[$driver])) {
            return $hasPool
                ? $this->createDriverPooledDisk(
                    $driver,
                    $config,
                    null,
                    fn () => $this->callCustomCreator($constructionConfig),
                )
                : $this->callCustomCreator($constructionConfig);
        }

        if ($hasPool && ($driver === 's3' || $driver === 'gcs')) {
            return $this->createClientPooledDisk($driver, $config);
        }

        $driverMethod = 'create' . ucfirst($driver) . 'Driver';

        if (! method_exists($this, $driverMethod)) {
            throw new InvalidArgumentException("Driver [{$driver}] is not supported.");
        }

        if ($hasPool) {
            return $this->createDriverPooledDisk(
                $driver,
                $config,
                $name,
                fn () => $this->{$driverMethod}($constructionConfig, $name),
            );
        }

        return $this->{$driverMethod}($config, $name);
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(array $config): mixed
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create a whole-driver pooled disk for a resource the framework cannot split.
     */
    protected function createDriverPooledDisk(
        string $driver,
        array $config,
        ?string $name,
        Closure $resolver,
    ): FilesystemPoolProxy {
        return new FilesystemPoolProxy(
            $this->diskPoolDefinition($driver, $config, $name),
            $resolver,
            $this->poolFactory(),
            $config,
            $this->getReleaseCallback($driver),
        );
    }

    /**
     * Create a client-pooled disk for a built-in cloud driver.
     */
    protected function createClientPooledDisk(string $driver, array $config): ClientPooledFilesystem
    {
        if ($driver === 's3') {
            $clientConfig = $this->s3ClientConfig($config);

            return new ClientPooledFilesystem(
                $this->poolDefinition($driver, $config['pool'] ?? [], $clientConfig),
                fn (): S3Client => $this->createS3Client($clientConfig),
                fn (S3Client $client): AwsS3V3Adapter => $this->buildS3Disk($client, $config),
                $this->poolFactory(),
                $config,
                $this->getReleaseCallback($driver),
            );
        }

        if ($driver === 'gcs') {
            $clientConfig = $this->gcsClientConfig($config);

            return new ClientPooledFilesystem(
                $this->poolDefinition($driver, $config['pool'] ?? [], $clientConfig),
                fn (): GcsClient => $this->createGcsClient($clientConfig),
                fn (GcsClient $client): GoogleCloudStorageAdapter => $this->buildGcsDisk($client, $config),
                $this->poolFactory(),
                $config,
                $this->getReleaseCallback($driver),
            );
        }

        throw new InvalidArgumentException("Driver [{$driver}] does not support client-level pooling.");
    }

    /**
     * Derive the immutable pool definition for a disk configuration.
     */
    protected function diskPoolDefinition(string $driver, array $config, ?string $name): PoolDefinition
    {
        $fingerprintSource = match (true) {
            $driver === 's3' => $this->s3ClientConfig($config),
            $driver === 'gcs' => $this->gcsClientConfig($config),
            default => [
                'config' => Arr::except($config, ['pool']),
                'name' => isset($this->customCreators[$driver]) ? null : $name,
            ],
        };

        return $this->poolDefinition($driver, $config['pool'] ?? [], $fingerprintSource);
    }

    /**
     * Create an instance of the local driver.
     */
    public function createLocalDriver(array $config, string $name = 'local'): Filesystem
    {
        $visibility = PortableVisibilityConverter::fromArray(
            $config['permissions'] ?? [],
            $config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE
        );

        $links = ($config['links'] ?? null) === 'skip'
            ? LocalAdapter::SKIP_LINKS
            : LocalAdapter::DISALLOW_LINKS;

        $adapter = new LocalAdapter(
            $config['root'],
            $visibility,
            $config['lock'] ?? LOCK_EX,
            $links
        );

        return (new LocalFilesystemAdapter(
            $this->createFlysystem($adapter, $config),
            $adapter,
            $config
        ))->diskName(
            $name
        )->shouldServeSignedUrls(
            $config['serve'] ?? false,
            fn () => $this->app['url'],
        );
    }

    /**
     * Create an instance of the ftp driver.
     */
    public function createFtpDriver(array $config): Filesystem
    {
        if (! isset($config['root'])) {
            $config['root'] = '';
        }

        /* @phpstan-ignore-next-line */
        $adapter = new FtpAdapter(FtpConnectionOptions::fromArray($config));

        return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config); // @phpstan-ignore-line
    }

    /**
     * Create an instance of the sftp driver.
     */
    public function createSftpDriver(array $config): Filesystem
    {
        /* @phpstan-ignore-next-line */
        $provider = SftpConnectionProvider::fromArray($config);

        $root = $config['root'] ?? '';

        $visibility = PortableVisibilityConverter::fromArray(
            $config['permissions'] ?? []
        );

        /* @phpstan-ignore-next-line */
        $adapter = new SftpAdapter($provider, $root, $visibility);

        return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config); // @phpstan-ignore-line
    }

    /**
     * Create an instance of the Amazon S3 driver.
     */
    public function createS3Driver(array $config): Cloud
    {
        return $this->buildS3Disk(
            $this->createS3Client($this->s3ClientConfig($config)),
            $config,
        );
    }

    /**
     * Derive the S3 client construction config from a disk config.
     */
    protected function s3ClientConfig(array $config): array
    {
        $s3Config = $this->formatS3Config($config);
        $arguments = static::s3ArgumentNames();

        return array_merge(
            Arr::only($s3Config, $arguments),
            $this->clientConfigBlock($s3Config, $arguments),
        );
    }

    /**
     * Get the S3 client constructor argument names.
     *
     * The SDK argument set is immutable for the worker's installed version.
     *
     * @return list<string>
     */
    protected static function s3ArgumentNames(): array
    {
        return static::$s3ArgumentNames ??= array_keys(S3Client::getArguments());
    }

    /**
     * Create an S3 client from normalized client config.
     */
    protected function createS3Client(array $clientConfig): S3Client
    {
        return new S3Client($clientConfig);
    }

    /**
     * Build an S3 disk adapter stack around a client.
     */
    protected function buildS3Disk(S3Client $client, array $config): AwsS3V3Adapter
    {
        $s3Config = $this->formatS3Config($config);

        $root = (string) ($s3Config['root'] ?? '');

        $visibility = new AwsS3PortableVisibilityConverter(
            $config['visibility'] ?? Visibility::PUBLIC
        );

        $streamReads = $s3Config['stream_reads'] ?? false;

        $adapter = new S3Adapter($client, $s3Config['bucket'], $root, $visibility, null, $config['options'] ?? [], $streamReads);

        return new AwsS3V3Adapter(
            $this->createFlysystem($adapter, $config),
            $adapter,
            $s3Config,
            $client
        );
    }

    /**
     * Format the given S3 configuration with the default options.
     */
    protected function formatS3Config(array $config): array
    {
        $config += ['version' => 'latest'];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);

            if (! empty($config['token'])) {
                $config['credentials']['token'] = $config['token'];
            }
        }

        return Arr::except($config, ['token']);
    }

    /**
     * Create an instance of the Google Cloud Storage driver.
     */
    public function createGcsDriver(array $config): Cloud
    {
        return $this->buildGcsDisk(
            $this->createGcsClient($this->gcsClientConfig($config)),
            $config,
        );
    }

    /**
     * Derive the Google Cloud Storage client construction config from a disk config.
     */
    protected function gcsClientConfig(array $config): array
    {
        $gcsConfig = $this->formatGcsConfig($config);

        return array_merge(
            Arr::only($gcsConfig, ['keyFilePath', 'keyFile', 'projectId', 'apiEndpoint']),
            $this->clientConfigBlock($gcsConfig, self::GCS_CLIENT_OPTIONS),
        );
    }

    /**
     * Create a Google Cloud Storage client from normalized client config.
     */
    protected function createGcsClient(array $clientConfig): GcsClient
    {
        return new GcsClient($clientConfig);
    }

    /**
     * Build a Google Cloud Storage disk adapter stack around a client.
     */
    protected function buildGcsDisk(GcsClient $client, array $config): GoogleCloudStorageAdapter
    {
        $gcsConfig = $this->formatGcsConfig($config);

        $visibilityHandlerClass = Arr::get($gcsConfig, 'visibilityHandler');
        $defaultVisibility = in_array(
            $visibility = Arr::get($gcsConfig, 'visibility'),
            [
                Visibility::PRIVATE,
                Visibility::PUBLIC,
            ],
            true
        ) ? $visibility : Visibility::PRIVATE;

        $adapter = new GcsAdapter(
            $client->bucket(Arr::get($gcsConfig, 'bucket')),
            Arr::get($gcsConfig, 'root'),
            Arr::get($gcsConfig, 'visibilityHandler') ? new $visibilityHandlerClass : null,
            $defaultVisibility
        );

        return new GoogleCloudStorageAdapter(
            $this->createFlysystem($adapter, $gcsConfig),
            $adapter,
            $gcsConfig,
            $client
        );
    }

    /**
     * Format the given GCS configuration with the default options.
     */
    protected function formatGcsConfig(array $config): array
    {
        // Google's SDK expects camelCase keys, but we can use snake_case in the config.
        foreach ($config as $key => $value) {
            $config[Str::camel($key)] = $value;
        }

        if (! Arr::has($config, 'root')) {
            $config['root'] = Arr::get($config, 'pathPrefix') ?? '';
        }

        return $config;
    }

    /**
     * Validate and return an explicit SDK client-option block.
     */
    protected function clientConfigBlock(array $config, array $supportedKeys): array
    {
        $block = array_key_exists('client', $config) ? $config['client'] : [];

        if (! is_array($block)) {
            throw new InvalidArgumentException('The disk "client" configuration option must be an array.');
        }

        $unknown = array_diff(array_keys($block), $supportedKeys);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown client option(s) [' . implode(', ', $unknown)
                . '] in the disk "client" configuration.'
            );
        }

        return $block;
    }

    /**
     * Create a scoped driver.
     *
     * @throws InvalidArgumentException
     */
    public function createScopedDriver(array $config): Filesystem
    {
        return $this->build($this->expandScopedConfig($config));
    }

    /**
     * Expand a scoped disk into the effective parent-disk configuration.
     */
    protected function expandScopedConfig(array $config): array
    {
        return $this->expandScopedConfigRecursively($config, []);
    }

    /**
     * Expand nested scoped disks while detecting named definition cycles.
     *
     * @param list<string> $diskStack
     */
    private function expandScopedConfigRecursively(array $config, array $diskStack): array
    {
        if (empty($config['disk'])) {
            throw new InvalidArgumentException('Scoped disk is missing "disk" configuration option.');
        }
        if (empty($config['prefix'])) {
            throw new InvalidArgumentException('Scoped disk is missing "prefix" configuration option.');
        }

        if (! is_string($config['disk']) && ! is_array($config['disk'])) {
            throw new InvalidArgumentException(
                'Scoped disk "disk" configuration option must be a disk name or configuration array.',
            );
        }

        if (! is_string($config['prefix'])) {
            throw new InvalidArgumentException('Scoped disk "prefix" configuration option must be a string.');
        }

        if (is_string($config['disk'])) {
            $disk = $config['disk'];

            if (($cycleStart = array_search($disk, $diskStack, true)) !== false) {
                $cycle = [...array_slice($diskStack, $cycleStart), $disk];

                throw new InvalidArgumentException(
                    'Circular scoped disk definition detected: ' . implode(' -> ', $cycle) . '.',
                );
            }

            $diskStack[] = $disk;
            $parent = $this->getConfig($disk);
        } else {
            $parent = $config['disk'];
        }

        if (empty($parent['prefix'])) {
            $parent['prefix'] = $config['prefix'];
        } else {
            $separator = $parent['directory_separator'] ?? DIRECTORY_SEPARATOR;
            $parentPrefix = rtrim($parent['prefix'], $separator);
            $scopedPrefix = ltrim($config['prefix'], $separator);
            $parent['prefix'] = "{$parentPrefix}{$separator}{$scopedPrefix}";
        }

        if (isset($config['visibility'])) {
            $parent['visibility'] = $config['visibility'];
        }

        if (isset($config['throw'])) {
            $parent['throw'] = $config['throw'];
        }

        return ($parent['driver'] ?? null) === 'scoped'
            ? $this->expandScopedConfigRecursively($parent, $diskStack)
            : $parent;
    }

    /**
     * Create a Flysystem instance with the given adapter.
     */
    protected function createFlysystem(FlysystemAdapter $adapter, array $config): FilesystemOperator
    {
        if ($config['read-only'] ?? false) {
            /* @phpstan-ignore-next-line */
            $adapter = new ReadOnlyFilesystemAdapter($adapter);
        }

        if (! empty($config['prefix'])) {
            /* @phpstan-ignore-next-line */
            $adapter = new PathPrefixedAdapter($adapter, $config['prefix']);
        }

        if (str_contains($config['endpoint'] ?? '', 'r2.cloudflarestorage.com')) {
            $config['retain_visibility'] = false;
        }

        return new Flysystem($adapter, Arr::only($config, [
            'directory_visibility',
            'disable_asserts',
            'retain_visibility',
            'temporary_url',
            'url',
            'visibility',
        ]));
    }

    /**
     * Set the given disk instance.
     *
     * Boot or tests only. Mutates the singleton's disk cache; concurrent
     * coroutines may already hold a reference to the prior disk and next
     * resolution will return the replacement. Any shared pool remains
     * available until its idle TTL expires or purge() invalidates it.
     */
    public function set(string $name, mixed $disk): static
    {
        $this->disks[$name] = $disk;

        return $this;
    }

    /**
     * Get the filesystem connection configuration.
     */
    protected function getConfig(string $name): array
    {
        return $this->app->make('config')->get("filesystems.disks.{$name}") ?: [];
    }

    /**
     * Get the shared object-pool factory.
     */
    protected function poolFactory(): PoolFactory
    {
        return $this->app->make(PoolFactory::class);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->make('config')->string('filesystems.default');
    }

    /**
     * Unset the given disk instances.
     *
     * Boot or tests only. Mutates the singleton's disk cache; concurrent
     * coroutines may already hold a reference to the disk and next resolution
     * will rebuild a wrapper. Shared pools remain available until their idle
     * TTL expires or purge() deliberately invalidates them.
     */
    public function forgetDisk(array|string $disk): static
    {
        foreach ((array) $disk as $diskName) {
            unset($this->disks[$diskName]);
        }

        return $this;
    }

    /**
     * Disconnect the given disk, remove it from local cache, and close its pool.
     *
     * Boot or tests only, plus operational recovery of broken pooled resources.
     * Closing deliberately invalidates a shared pool; other converged disks
     * acquire a fresh pool on their next operation.
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultDriver();

        $disk = $this->disks[$name] ?? null;
        unset($this->disks[$name]);

        if ($disk instanceof ClientPooledFilesystem || $disk instanceof FilesystemPoolProxy) {
            $disk->invalidatePool();

            return;
        }

        $config = $this->getConfig($name);

        if (($config['driver'] ?? null) === 'scoped') {
            // Scoped disks resolve their expanded parent through build(). Use
            // that same logical name because whole-driver fingerprints include it.
            $config = $this->expandScopedConfig($config);
            $name = self::ON_DEMAND_DISK_NAME;
        }

        $driver = $config['driver'] ?? null;

        if (is_string($driver) && in_array($driver, $this->poolables, true)) {
            $this->poolFactory()->remove($this->diskPoolDefinition($driver, $config, $name)->identity);
        }
    }

    /**
     * Register a custom driver creator Closure.
     *
     * Boot-only. The callback persists in the singleton's customCreators array
     * (and the poolable list if $poolable is true) for the worker lifetime and
     * applies to every subsequent disk resolution.
     */
    public function extend(string $driver, Closure $callback, bool $poolable = false): static
    {
        if ($poolable) {
            $this->addPoolable($driver);
        }

        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     *
     * Tests only. Swaps the singleton's container reference; per-request use
     * races across coroutines and breaks every concurrent filesystem operation.
     */
    public function setApplication(Container $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$s3ArgumentNames = null;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->disk()->{$method}(...$parameters);
    }
}
