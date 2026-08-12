<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Aws\SesV2\SesV2Client;
use Closure;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Mail\Factory as FactoryContract;
use Hypervel\Contracts\Mail\Mailer as MailerContract;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Log\LogManager;
use Hypervel\Mail\Transport\ArrayTransport;
use Hypervel\Mail\Transport\CloudflareTransport;
use Hypervel\Mail\Transport\LogTransport;
use Hypervel\Mail\Transport\ResendTransport;
use Hypervel\Mail\Transport\SesV2Transport;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Support\Arr;
use Hypervel\Support\ConfigurationUrlParser;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Resend;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Mail\Mailer
 */
class MailManager implements FactoryContract
{
    use HasPoolProxy;

    /**
     * Mailer-level keys that do not affect built-in transport construction.
     */
    protected const TRANSPORT_PRESENTATION_KEYS = [
        'from', 'reply_to', 'return_path', 'to', 'name', 'pool',
    ];

    /**
     * The config instance.
     */
    protected Repository $config;

    /**
     * The array of resolved mailers.
     */
    protected array $mailers = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The array of drivers which will be wrapped as pool proxies.
     */
    // API transports use Symfony HTTP clients that keep mutable request state on
    // the transport instance. Pooling ensures each concurrent coroutine borrows a
    // separate transport/client pair instead of sharing one mailer-held instance.
    protected array $poolables = [
        'smtp', 'sendmail', 'mailgun', 'ses-v2', 'postmark', 'resend', 'cloudflare', 'failover', 'roundrobin',
    ];

    /**
     * Create a new Mail manager instance.
     */
    public function __construct(
        protected Container $app
    ) {
        $this->config = $app->make('config');
    }

    /**
     * Get a mailer instance by name.
     */
    public function mailer(UnitEnum|string|null $name = null): MailerContract
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === ''
            ? $this->getDefaultDriver()
            : $name;

        return $this->mailers[$name] = $this->get($name);
    }

    /**
     * Get a mailer driver instance.
     */
    public function driver(UnitEnum|string|null $driver = null): MailerContract
    {
        return $this->mailer($driver);
    }

    /**
     * Attempt to get the mailer from the local cache.
     */
    protected function get(string $name): MailerContract
    {
        return $this->mailers[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given mailer.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): MailerContract
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
        }

        /** @var ViewFactory $views */
        $views = $this->app->make('view');
        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        $mailer = new Mailer(
            $name,
            $views,
            $this->createMailerTransport($config, [$name], poolByDefault: true),
            $events
        );

        if ($this->app->bound('queue')) {
            /** @var QueueFactory $queue */
            $queue = $this->app->make('queue');
            $mailer->setQueue($queue);
        }

        // Next we will set all of the global addresses on this mailer, which allows
        // for easy unification of all "from" addresses as well as easy debugging
        // of sent messages since these will be sent to a single email address.
        foreach (['from', 'reply_to', 'to', 'return_path'] as $type) {
            $this->setGlobalAddress($mailer, $config, $type);
        }

        return $mailer;
    }

    /**
     * Build a new mailer instance.
     */
    public function build(array $config): Mailer
    {
        /** @var ViewFactory $views */
        $views = $this->app->make('view');
        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        $mailer = new Mailer(
            $config['name'] ?? 'ondemand',
            $views,
            $this->createMailerTransport($config),
            $events
        );

        if ($this->app->bound('queue')) {
            /** @var QueueFactory $queue */
            $queue = $this->app->make('queue');
            $mailer->setQueue($queue);
        }

        return $mailer;
    }

    /**
     * Create a new transport instance.
     *
     * @throws InvalidArgumentException
     */
    public function createSymfonyTransport(array $config): TransportInterface
    {
        return $this->createSymfonyTransportFromConstructionConfig(
            $this->transportConstructionConfig($config)
        );
    }

    /**
     * Create a pooled or direct transport for a mailer.
     *
     * @param list<string> $mailerStack
     */
    protected function createMailerTransport(
        array $config,
        array $mailerStack = [],
        bool $poolByDefault = false,
    ): TransportInterface {
        $constructionConfig = $this->transportConstructionConfig($config, $mailerStack);
        $transport = $constructionConfig['transport'];
        $poolConfig = $this->transportPoolConfig($transport, $config, $poolByDefault);

        if ($poolConfig === null) {
            return $this->createSymfonyTransportFromConstructionConfig($constructionConfig);
        }

        return $this->createPoolProxy(
            $transport,
            fn () => $this->createSymfonyTransportFromConstructionConfig($constructionConfig),
            $this->poolDefinition($transport, $poolConfig, $constructionConfig),
            TransportPoolProxy::class,
        );
    }

    /**
     * Resolve whether and how a mail transport should be pooled.
     */
    protected function transportPoolConfig(string $transport, array $config, bool $poolByDefault): ?array
    {
        if (! array_key_exists('pool', $config)) {
            return $poolByDefault && in_array($transport, $this->poolables, true) ? [] : null;
        }

        $pool = $config['pool'];

        if ($pool === false) {
            return null;
        }

        if ($pool === true) {
            $pool = [];
        } elseif (! is_array($pool)) {
            throw new InvalidArgumentException(
                'Mail transport pool configuration must be false, true, or an array of pool options.'
            );
        }

        if (! in_array($transport, $this->poolables, true)) {
            throw new InvalidArgumentException("Mail transport [{$transport}] is not registered as poolable.");
        }

        return $pool;
    }

    /**
     * Create a transport from its fully resolved construction config.
     */
    protected function createSymfonyTransportFromConstructionConfig(array $config): TransportInterface
    {
        $transport = $config['transport'];

        if (isset($this->customCreators[$transport])) {
            return call_user_func($this->customCreators[$transport], $config);
        }

        $method = 'create' . ucfirst(Str::camel($transport)) . 'Transport';

        return $this->{$method}($config);
    }

    /**
     * Resolve the exact configuration consumed to construct a transport.
     *
     * @param list<string> $mailerStack
     * @return array{transport: string, ...}
     */
    protected function transportConstructionConfig(array $config, array $mailerStack = []): array
    {
        $transport = $config['transport'] ?? null;

        if (! is_string($transport)
            || trim($transport) === ''
            || (! isset($this->customCreators[$transport])
                && ! method_exists($this, 'create' . ucfirst(Str::camel($transport)) . 'Transport'))
        ) {
            throw new InvalidArgumentException('Unsupported mail transport [' . (is_scalar($transport) ? $transport : '') . '].');
        }

        if (isset($this->customCreators[$transport])) {
            return Arr::except($config, ['pool']);
        }

        $config = Arr::except($config, self::TRANSPORT_PRESENTATION_KEYS);

        return match ($transport) {
            'sendmail' => [
                'transport' => $transport,
                'path' => $config['path'] ?? null,
            ],
            'ses-v2' => array_merge(
                $this->config->array('services.ses', []),
                ['version' => 'latest'],
                $config,
            ),
            'resend' => [
                'transport' => $transport,
                'key' => $config['key'] ?? $this->config->get('services.resend.key'),
            ],
            'cloudflare' => [
                'transport' => $transport,
                'account_id' => $config['account_id']
                    ?? $this->config->get('services.cloudflare.account_id'),
                'token' => $config['token']
                    ?? $config['key']
                    ?? $this->config->get('services.cloudflare.token')
                    ?? $this->config->get('services.cloudflare.key'),
                ...$this->httpClientConstructionConfig($config),
            ],
            'mailgun' => $this->mailgunTransportConstructionConfig($config),
            'postmark' => [
                'transport' => $transport,
                'token' => $config['token']
                    ?? $config['key']
                    ?? $this->config->get('services.postmark.token')
                    ?? $this->config->get('services.postmark.key'),
                ...(isset($config['message_stream_id'])
                    ? ['message_stream_id' => $config['message_stream_id']]
                    : []),
                ...$this->httpClientConstructionConfig($config),
            ],
            'failover', 'roundrobin' => $this->compositeTransportConstructionConfig(
                $transport,
                $config,
                $mailerStack,
            ),
            'log' => [
                'transport' => $transport,
                'channel' => $config['channel'] ?? null,
            ],
            'mail', 'array' => ['transport' => $transport],
            default => $config,
        };
    }

    /**
     * Resolve Mailgun's credential and HTTP-client construction config.
     *
     * @return array{transport: string, scheme: mixed, endpoint: mixed, secret: mixed, domain: mixed, ...}
     */
    protected function mailgunTransportConstructionConfig(array $config): array
    {
        $credentials = isset($config['secret'])
            ? $config
            : $this->config->array('services.mailgun', []);

        return [
            'transport' => 'mailgun',
            'scheme' => $credentials['scheme'] ?? 'https',
            'endpoint' => $credentials['endpoint'] ?? 'default',
            'secret' => $credentials['secret'] ?? null,
            'domain' => $credentials['domain'] ?? null,
            ...$this->httpClientConstructionConfig($config),
        ];
    }

    /**
     * Resolve a composite transport's ordered child construction configs.
     *
     * @param list<string> $mailerStack
     * @return array{transport: string, mailers: list<array{transport: string, ...}>, retry_after: mixed}
     */
    protected function compositeTransportConstructionConfig(
        string $transport,
        array $config,
        array $mailerStack,
    ): array {
        $mailers = [];

        foreach ($config['mailers'] as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Composite mailer transports require non-empty mailer names.');
            }

            if (($cycleStart = array_search($name, $mailerStack, true)) !== false) {
                $cycle = [...array_slice($mailerStack, $cycleStart), $name];

                throw new InvalidArgumentException(
                    'Circular mailer transport definition detected: ' . implode(' -> ', $cycle) . '.'
                );
            }

            $childConfig = $this->getConfig($name);

            if (is_null($childConfig)) {
                throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
            }

            $mailers[] = $this->transportConstructionConfig(
                $childConfig,
                [...$mailerStack, $name],
            );
        }

        return [
            'transport' => $transport,
            'mailers' => $mailers,
            'retry_after' => $config['retry_after'] ?? 60,
        ];
    }

    /**
     * Extract HTTP-client options that affect transport construction.
     */
    protected function httpClientConstructionConfig(array $config): array
    {
        return ($config['client'] ?? false) ? ['client' => $config['client']] : [];
    }

    /**
     * Create an instance of the Symfony SMTP Transport driver.
     */
    protected function createSmtpTransport(array $config): EsmtpTransport
    {
        $factory = new EsmtpTransportFactory;

        $scheme = $config['scheme'] ?? null;

        if (! $scheme) {
            $scheme = ((int) $config['port'] === 465) ? 'smtps' : 'smtp';
        }

        /** @var EsmtpTransport $transport */
        $transport = $factory->create(new Dsn(
            $scheme,
            $config['host'],
            $config['username'] ?? null,
            $config['password'] ?? null,
            isset($config['port']) ? (int) $config['port'] : null,
            $config
        ));

        return $this->configureSmtpTransport($transport, $config);
    }

    /**
     * Configure the additional SMTP driver options.
     */
    protected function configureSmtpTransport(EsmtpTransport $transport, array $config): EsmtpTransport
    {
        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            if (isset($config['source_ip'])) {
                $stream->setSourceIp($config['source_ip']);
            }

            if (isset($config['timeout'])) {
                $stream->setTimeout($config['timeout']);
            }
        }

        return $transport;
    }

    /**
     * Create an instance of the Symfony Sendmail Transport driver.
     */
    protected function createSendmailTransport(array $config): SendmailTransport
    {
        return new SendmailTransport($config['path']);
    }

    // REMOVED: Hypervel supports Amazon SES through the SES v2 API only.

    /**
     * Create an instance of the Symfony Amazon SES V2 Transport driver.
     */
    protected function createSesV2Transport(array $config): SesV2Transport
    {
        $config = Arr::except($config, ['transport']);

        return new SesV2Transport(
            new SesV2Client($this->addSesCredentials($config)),
            $config['options'] ?? []
        );
    }

    /**
     * Add the SES credentials to the configuration array.
     */
    protected function addSesCredentials(array $config): array
    {
        if (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);

            if (! empty($config['token'])) {
                $config['credentials']['token'] = $config['token'];
            }
        }

        return Arr::except($config, ['token']);
    }

    /**
     * Create an instance of the Resend Transport driver.
     */
    protected function createResendTransport(array $config): ResendTransport
    {
        return new ResendTransport(Resend::client($config['key']));
    }

    /**
     * Create an instance of the Cloudflare Transport driver.
     */
    protected function createCloudflareTransport(array $config): CloudflareTransport
    {
        return new CloudflareTransport(
            $config['account_id'],
            $config['token'],
            $this->getHttpClient($config),
        );
    }

    /**
     * Create an instance of the Symfony Mail Transport driver.
     */
    protected function createMailTransport(): SendmailTransport
    {
        return new SendmailTransport;
    }

    /**
     * Create an instance of the Symfony Mailgun Transport driver.
     */
    protected function createMailgunTransport(array $config): TransportInterface
    {
        /* @phpstan-ignore-next-line */
        $factory = new MailgunTransportFactory(null, $this->getHttpClient($config));

        /* @phpstan-ignore-next-line */
        return $factory->create(new Dsn(
            'mailgun+' . $config['scheme'],
            $config['endpoint'],
            $config['secret'],
            $config['domain']
        ));
    }

    /**
     * Create an instance of the Symfony Postmark Transport driver.
     */
    protected function createPostmarkTransport(array $config): PostmarkApiTransport
    {
        $factory = new PostmarkTransportFactory(null, $this->getHttpClient($config));

        $options = isset($config['message_stream_id'])
            ? ['message_stream' => $config['message_stream_id']]
            : [];

        return $factory->create(new Dsn( // @phpstan-ignore return.type
            'postmark+api',
            'default',
            $config['token'],
            null,
            null,
            $options
        ));
    }

    /**
     * Create an instance of the Symfony Failover Transport driver.
     */
    protected function createFailoverTransport(array $config): FailoverTransport
    {
        return $this->createRoundrobinTransportOfClass($config, FailoverTransport::class);
    }

    /**
     * Create an instance of the Symfony Roundrobin Transport driver.
     */
    protected function createRoundrobinTransport(array $config): RoundRobinTransport
    {
        return $this->createRoundrobinTransportOfClass($config, RoundRobinTransport::class);
    }

    /**
     * Create an instance of supplied class extending the Symfony Roundrobin Transport driver.
     *
     * @template TClass of RoundRobinTransport
     *
     * @param class-string<TClass> $class
     * @return TClass
     *
     * @throws InvalidArgumentException
     */
    protected function createRoundrobinTransportOfClass(array $config, string $class): RoundRobinTransport
    {
        $transports = [];

        foreach ($config['mailers'] as $childConfig) {
            $transports[] = $this->createSymfonyTransportFromConstructionConfig($childConfig);
        }

        return new $class($transports, $config['retry_after'] ?? 60, $this->app->make(LoggerInterface::class));
    }

    /**
     * Create an instance of the Log Transport driver.
     */
    protected function createLogTransport(array $config): LogTransport
    {
        $logger = $this->app->make(LoggerInterface::class);

        if ($logger instanceof LogManager) {
            $logger = $logger->channel($config['channel']);
        }

        return new LogTransport($logger);
    }

    /**
     * Create an instance of the Array Transport Driver.
     */
    protected function createArrayTransport(): ArrayTransport
    {
        return new ArrayTransport;
    }

    /**
     * Get a configured Symfony HTTP client instance.
     *
     * @phpstan-ignore-next-line
     */
    protected function getHttpClient(array $config): ?HttpClientInterface
    {
        // Symfony mail transports require Symfony's HttpClientInterface, not PSR-18.
        // We can't use a simple Guzzle adapter here, so coroutine safety must come
        // from pooling configured transports rather than swapping the HTTP client.
        if ($options = ($config['client'] ?? false)) {
            $maxHostConnections = Arr::pull($options, 'max_host_connections', 6);
            $maxPendingPushes = Arr::pull($options, 'max_pending_pushes', 50);

            /* @phpstan-ignore-next-line */
            return HttpClient::create($options, $maxHostConnections, $maxPendingPushes);
        }

        return null;
    }

    /**
     * Set a global address on the mailer by type.
     */
    protected function setGlobalAddress(Mailer $mailer, array $config, string $type): void
    {
        $address = Arr::get($config, $type, $this->config->get('mail.' . $type));

        if (is_array($address) && isset($address['address'])) {
            $mailer->{'always' . Str::studly($type)}($address['address'], $address['name'] ?? null);
        }
    }

    /**
     * Get the mail connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        $config = $this->config->get("mail.mailers.{$name}");

        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));

            $config['transport'] = Arr::pull($config, 'driver');
        }

        return $config;
    }

    /**
     * Get the shared object-pool factory.
     */
    protected function poolFactory(): PoolFactory
    {
        return $this->app->make(PoolFactory::class);
    }

    /**
     * Get the default mail driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->string('mail.default');
    }

    /**
     * Set the default mail driver name.
     *
     * Boot-only. Mutates process-global config; per-request use races across coroutines.
     */
    public function setDefaultDriver(UnitEnum|string $name): void
    {
        $name = $name instanceof UnitEnum ? (string) enum_value($name) : $name;

        $this->config->set('mail.default', $name);
    }

    /**
     * Disconnect the given mailer and close its shared transport pool.
     *
     * Boot or tests only, plus operational recovery of broken pooled
     * resources. Other mailers sharing the pool transparently acquire a fresh
     * pool on their next operation.
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === ''
            ? $this->getDefaultDriver()
            : $name;
        $mailer = $this->mailers[$name] ?? null;

        unset($this->mailers[$name]);

        if ($mailer !== null) {
            $transport = $mailer->getSymfonyTransport();

            if ($transport instanceof TransportPoolProxy) {
                $transport->invalidatePool();
            }

            return;
        }

        $config = $this->getConfig($name);

        if (is_null($config)) {
            return;
        }

        $constructionConfig = $this->transportConstructionConfig($config, [$name]);
        $transport = $constructionConfig['transport'];

        if (($poolConfig = $this->transportPoolConfig($transport, $config, poolByDefault: true)) !== null) {
            $definition = $this->poolDefinition(
                $transport,
                $poolConfig,
                $constructionConfig,
            );

            $this->poolFactory()->remove($definition->identity);
        }
    }

    /**
     * Register a custom driver creator Closure.
     *
     * Boot-only. The callback persists in the singleton's customCreators array
     * (and the poolable list if $poolable is true) for the worker lifetime and
     * applies to every subsequent transport resolution.
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
     * Get the application instance used by the manager.
     */
    public function getApplication(): Container
    {
        return $this->app;
    }

    /**
     * Set the application instance used by the manager.
     *
     * Tests only. Swaps the singleton's container and configuration references
     * without rebuilding resolved mailers; per-request use races across
     * coroutines and breaks every concurrent mail send.
     */
    public function setApplication(Container $app): static
    {
        $this->app = $app;
        $this->config = $app->make('config');

        return $this;
    }

    /**
     * Forget all of the resolved mailer instances.
     *
     * Boot or tests only. This is cache-only: pooled transports remain shared
     * resources until purged or reclaimed by their idle TTL.
     */
    public function forgetMailers(): static
    {
        $this->mailers = [];

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters)
    {
        return $this->mailer()->{$method}(...$parameters);
    }
}
