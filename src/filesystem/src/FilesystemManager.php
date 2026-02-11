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
 * @mixin \Hypervel\Filesystem\Filesystem
 * @mixin \Hypervel\Filesystem\FilesystemAdapter
 */
class FilesystemManager implements FactoryContract
{
    use HasPoolProxy;

    /**
     * The array of resolved filesystem drivers.
     */
    protected array $disks = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The pool proxy class.
     */
    protected string $poolProxyClass = FilesystemPoolProxy::class;

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
    public function drive(UnitEnum|string|null $name = null): Filesystem
    {
        return $this->disk($name);
    }

    /**
     * Get a filesystem instance.
     */
    public function disk(UnitEnum|string|null $name = null): FileSystem
    {
        $name = enum_value($name) ?: $this->getDefaultDriver();

        return $this->disks[$name] = $this->get($name);
    }

    /**
     * Get a default cloud filesystem instance.
     */
    public function cloud(): Cloud
    {
        $name = $this->getDefaultCloudDriver();

        /* @phpstan-ignore-next-line */
        return $this->disks[$name] = $this->get($name);
    }

    /**
     * Build an on-demand disk.
     */
    public function build(array|string $config): FileSystem
    {
        return $this->resolve('ondemand', is_array($config) ? $config : [
            'driver' => 'local',
            'root' => $config,
        ]);
    }

    /**
     * Attempt to get the disk from the local cache.
     */
    protected function get(string $name): FileSystem
    {
        return $this->disks[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given disk.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name, ?array $config = null): FileSystem
    {
        $config ??= $this->getConfig($name);

        if (empty($config['driver'])) {
            throw new InvalidArgumentException("Disk [{$name}] does not have a configured driver.");
        }

        $driver = $config['driver'];
        $hasPool = in_array($driver, $this->poolables);

        if (isset($this->customCreators[$driver])) {
            if ($hasPool) {
                return $this->createPoolProxy(
                    $name,
                    fn () => $this->callCustomCreator($config),
                    $config['pool'] ?? []
                );
            }
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create' . ucfirst($driver) . 'Driver';

        if (! method_exists($this, $driverMethod)) {
            throw new InvalidArgumentException("Driver [{$driver}] is not supported.");
        }

        if ($hasPool) {
            return $this->createPoolProxy(
                $name,
                fn () => $this->{$driverMethod}($config, $name),
                $config['pool'] ?? []
            );
        }

        return $this->{$driverMethod}($config, $name);
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(array $config): FileSystem
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create an instance of the local driver.
     */
    public function createLocalDriver(array $config, string $name = 'local'): FileSystem
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
    public function createFtpDriver(array $config): FileSystem
    {
        if (! isset($config['root'])) {
            $config['root'] = '';
        }

        /* @phpstan-ignore-next-line */
        $adapter = new FtpAdapter(FtpConnectionOptions::fromArray($config));

        return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config); // @phpstan-ignore-line (FtpAdapter/SftpAdapter implement interface)
    }

    /**
     * Create an instance of the sftp driver.
     */
    public function createSftpDriver(array $config): FileSystem
    {
        /* @phpstan-ignore-next-line */
        $provider = SftpConnectionProvider::fromArray($config);

        $root = $config['root'] ?? '';

        $visibility = PortableVisibilityConverter::fromArray(
            $config['permissions'] ?? []
        );

        /* @phpstan-ignore-next-line */
        $adapter = new SftpAdapter($provider, $root, $visibility);

        return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config); // @phpstan-ignore-line (FtpAdapter/SftpAdapter implement interface)
    }

    /**
     * Create an instance of the Amazon S3 driver.
     */
    public function createS3Driver(array $config): Cloud
    {
        $s3Config = $this->formatS3Config($config);

        $root = (string) ($s3Config['root'] ?? '');

        $visibility = new AwsS3PortableVisibilityConverter(
            $config['visibility'] ?? Visibility::PUBLIC
        );

        $streamReads = $s3Config['stream_reads'] ?? false;

        $client = new S3Client($s3Config);

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
        }

        if (! empty($config['token'])) {
            $config['credentials']['token'] = $config['token'];
        }

        return Arr::except($config, ['token']);
    }

    /**
     * Create an instance of the Google Cloud Storage driver.
     */
    public function createGcsDriver(array $config): Cloud
    {
        $gcsConfig = $this->formatGcsConfig($config);
        $client = $this->createGcsClient($gcsConfig);

        $visibilityHandlerClass = Arr::get($gcsConfig, 'visibilityHandler');
        $defaultVisibility = in_array(
            $visibility = Arr::get($gcsConfig, 'visibility'),
            [
                Visibility::PRIVATE,
                Visibility::PUBLIC,
            ]
        ) ? $visibility : Visibility::PRIVATE;

        $adapter = new GcsAdapter(
            $client->bucket(Arr::get($gcsConfig, 'bucket')),
            Arr::get($gcsConfig, 'root'),
            Arr::get($gcsConfig, 'visibilityHandler') ? new $visibilityHandlerClass() : null,
            $defaultVisibility
        );

        return new GoogleCloudStorageAdapter(
            new Flysystem($adapter, $gcsConfig),
            $adapter,
            $gcsConfig,
            $client
        );
    }

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

    protected function createGcsClient(array $config): GcsClient
    {
        $options = [];

        if ($keyFilePath = Arr::get($config, 'keyFilePath')) {
            $options['keyFilePath'] = $keyFilePath;
        }

        if ($keyFile = Arr::get($config, 'keyFile')) {
            $options['keyFile'] = $keyFile;
        }

        if ($projectId = Arr::get($config, 'projectId')) {
            $options['projectId'] = $projectId;
        }

        if ($apiEndpoint = Arr::get($config, 'apiEndpoint')) {
            $options['apiEndpoint'] = $apiEndpoint;
        }

        return new GcsClient($options);
    }

    /**
     * Create a scoped driver.
     */
    public function createScopedDriver(array $config): FileSystem
    {
        if (empty($config['disk'])) {
            throw new InvalidArgumentException('Scoped disk is missing "disk" configuration option.');
        }
        if (empty($config['prefix'])) {
            throw new InvalidArgumentException('Scoped disk is missing "prefix" configuration option.');
        }

        return $this->build(tap(
            is_string($config['disk']) ? $this->getConfig($config['disk']) : $config['disk'],
            function (&$parent) use ($config) {
                $parent['prefix'] = $config['prefix'];

                if (isset($config['visibility'])) {
                    $parent['visibility'] = $config['visibility'];
                }
            }
        ));
    }

    /**
     * Create a Flysystem instance with the given adapter.
     */
    protected function createFlysystem(FlysystemAdapter $adapter, array $config): FilesystemOperator
    {
        if (($config['read-only'] ?? false) === true) {
            /* @phpstan-ignore-next-line */
            $adapter = new ReadOnlyFilesystemAdapter($adapter);
        }

        if (! empty($config['prefix'])) {
            /* @phpstan-ignore-next-line */
            $adapter = new PathPrefixedAdapter($adapter, $config['prefix']);
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
     * @return $this
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
        return $this->app->get('config')
            ->get("filesystems.disks.{$name}", []);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->get('config')
            ->get('filesystems.default');
    }

    /**
     * Get the default cloud driver name.
     */
    public function getDefaultCloudDriver(): string
    {
        return $this->app->get('config')
            ->get('filesystems.cloud', 's3');
    }

    /**
     * Unset the given disk instances.
     *
     * @return $this
     */
    public function forgetDisk(array|string $disk): static
    {
        foreach ((array) $disk as $diskName) {
            unset($this->disks[$diskName]);
        }

        return $this;
    }

    /**
     * Disconnect the given disk and remove from local cache.
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultDriver();

        unset($this->disks[$name]);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @return $this
     */
    public function extend(string $driver, Closure $callback, bool $poolable = false): static
    {
        if ($poolable) {
            $this->addPoolable($driver);
        }

        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(Container $app): static
    {
        $this->app = $app;

        return $this;
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
