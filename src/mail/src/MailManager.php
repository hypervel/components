<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Closure;
use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use Hypervel\Log\LogManager;
use Hypervel\Mail\Contracts\Factory as FactoryContract;
use Hypervel\Mail\Contracts\Mailer as MailerContract;
use Hypervel\Mail\Transport\ArrayTransport;
use Hypervel\Mail\Transport\LogTransport;
use Hypervel\Mail\Transport\SesTransport;
use Hypervel\Mail\Transport\SesV2Transport;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Queue\Contracts\Factory as QueueFactory;
use Hypervel\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
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

/**
 * @mixin \Hypervel\Mail\Contracts\Mailer
 */
class MailManager implements FactoryContract
{
    use HasPoolProxy;

    /**
     * The config instance.
     */
    protected ConfigInterface $config;

    /**
     * The array of resolved mailers.
     */
    protected array $mailers = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The pool proxy class.
     */
    protected string $poolProxyClass = TransportPoolProxy::class;

    /**
     * The array of drivers which will be wrapped as pool proxies.
     */
    protected array $poolables = [
        'smtp', 'sendmail', 'mailgun', 'ses', 'ses_v2', 'postmark', 'resend', 'failover', 'roundrobin',
    ];

    /**
     * Create a new Mail manager instance.
     */
    public function __construct(
        protected ContainerInterface $app
    ) {
        $this->config = $app->get(ConfigInterface::class);
    }

    /**
     * Get a mailer instance by name.
     */
    public function mailer(?string $name = null): MailerContract
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->mailers[$name] = $this->get($name);
    }

    /**
     * Get a mailer driver instance.
     */
    public function driver(?string $driver = null): MailerContract
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

        $transport = $config['transport'] ?? $this->config->get('mail.driver');
        $hasPool = in_array($transport, $this->poolables);

        // Once we have created the mailer instance we will set a container instance
        // on the mailer. This allows us to resolve mailer classes via containers
        // for maximum testability on said classes instead of passing Closures.
        $mailer = new Mailer(
            $name,
            $this->app->get(FactoryInterface::class),
            $this->createSymfonyTransport($config, $hasPool ? $name : null),
            $this->app->get(EventDispatcherInterface::class)
        );

        if ($this->app->has(QueueFactory::class)) {
            $mailer->setQueue($this->app->get(QueueFactory::class));
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
     * Create a new transport instance.
     *
     * @throws InvalidArgumentException
     */
    public function createSymfonyTransport(array $config, ?string $poolName = null): TransportInterface
    {
        // Here we will check if the "transport" key exists and if it doesn't we will
        // assume an application is still using the legacy mail configuration file
        // format and use the "mail.driver" configuration option instead for BC.
        $transport = $config['transport'] ?? $this->config->get('mail.driver');

        if (isset($this->customCreators[$transport])) {
            if (! is_null($poolName)) {
                return $this->createPoolProxy(
                    $poolName,
                    fn () => call_user_func($this->customCreators[$transport], $config),
                    $config['pool'] ?? []
                );
            }
            return call_user_func($this->customCreators[$transport], $config);
        }

        if (trim($transport ?? '') === ''
            || ! method_exists($this, $method = 'create' . ucfirst(Str::camel($transport)) . 'Transport')
        ) {
            throw new InvalidArgumentException("Unsupported mail transport [{$transport}].");
        }

        if (! is_null($poolName)) {
            return $this->createPoolProxy(
                $poolName,
                fn () => $this->{$method}($config),
                $config['pool'] ?? []
            );
        }

        return $this->{$method}($config);
    }

    /**
     * Create an instance of the Symfony SMTP Transport driver.
     */
    protected function createSmtpTransport(array $config): EsmtpTransport
    {
        $factory = new EsmtpTransportFactory();

        $scheme = $config['scheme'] ?? null;

        if (! $scheme) {
            $scheme = ! empty($config['encryption']) && $config['encryption'] === 'tls'
                ? (($config['port'] == 465) ? 'smtps' : 'smtp')
                : '';
        }

        $transport = $factory->create(new Dsn(
            $scheme,
            $config['host'],
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['port'] ?? null,
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
        return new SendmailTransport(
            $config['path'] ?? $this->config->get('mail.sendmail')
        );
    }

    /**
     * Create an instance of the Symfony Amazon SES Transport driver.
     */
    protected function createSesTransport(array $config): SesTransport
    {
        $config = array_merge(
            $this->config->get('services.ses', []),
            ['version' => 'latest', 'service' => 'email'],
            $config
        );

        $config = Arr::except($config, ['transport']);

        return new SesTransport(
            new SesClient($this->addSesCredentials($config)),
            $config['options'] ?? []
        );
    }

    /**
     * Create an instance of the Symfony Amazon SES V2 Transport driver.
     */
    protected function createSesV2Transport(array $config): SesV2Transport
    {
        $config = array_merge(
            $this->config->get('services.ses', []),
            ['version' => 'latest'],
            $config
        );

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
            $config['credentials'] = Arr::only($config, ['key', 'secret', 'token']);
        }

        return Arr::except($config, ['token']);
    }

    /**
     * Create an instance of the Symfony Mail Transport driver.
     */
    protected function createMailTransport(): SendmailTransport
    {
        return new SendmailTransport();
    }

    /**
     * Create an instance of the Symfony Mailgun Transport driver.
     */
    protected function createMailgunTransport(array $config): TransportInterface
    {
        /* @phpstan-ignore-next-line */
        $factory = new MailgunTransportFactory(null, $this->getHttpClient($config));

        if (! isset($config['secret'])) {
            $config = $this->config->get('services.mailgun', []);
        }

        /* @phpstan-ignore-next-line */
        return $factory->create(new Dsn(
            'mailgun+' . ($config['scheme'] ?? 'https'),
            $config['endpoint'] ?? 'default',
            $config['secret'],
            $config['domain']
        ));
    }

    /**
     * Create an instance of the Symfony Postmark Transport driver.
     *
     * @phpstan-ignore-next-line
     */
    protected function createPostmarkTransport(array $config): PostmarkApiTransport
    {
        /* @phpstan-ignore-next-line */
        $factory = new PostmarkTransportFactory(null, $this->getHttpClient($config));

        $options = isset($config['message_stream_id'])
            ? ['message_stream' => $config['message_stream_id']]
            : [];

        /* @phpstan-ignore-next-line */
        return $factory->create(new Dsn(
            'postmark+api',
            'default',
            $config['token'] ?? $this->config->get('services.postmark.token'),
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
        $transports = [];

        foreach ($config['mailers'] as $name) {
            $config = $this->getConfig($name);

            if (is_null($config)) {
                throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
            }

            // Now, we will check if the "driver" key exists and if it does we will set
            // the transport configuration parameter in order to offer compatibility
            // with any Laravel <= 6.x application style mail configuration files.
            $transports[] = $this->config->get('mail.driver')
                ? $this->createSymfonyTransport(array_merge($config, ['transport' => $name]))
                : $this->createSymfonyTransport($config);
        }

        return new FailoverTransport($transports);
    }

    /**
     * Create an instance of the Symfony Roundrobin Transport driver.
     */
    protected function createRoundrobinTransport(array $config): RoundRobinTransport
    {
        $transports = [];

        foreach ($config['mailers'] as $name) {
            $config = $this->getConfig($name);

            if (is_null($config)) {
                throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
            }

            // Now, we will check if the "driver" key exists and if it does we will set
            // the transport configuration parameter in order to offer compatibility
            // with any Laravel <= 6.x application style mail configuration files.
            $transports[] = $this->config->get('mail.driver')
                ? $this->createSymfonyTransport(array_merge($config, ['transport' => $name]))
                : $this->createSymfonyTransport($config);
        }

        return new RoundRobinTransport($transports);
    }

    /**
     * Create an instance of the Log Transport driver.
     */
    protected function createLogTransport(array $config): LogTransport
    {
        $logger = $this->app->get(LoggerInterface::class);

        if ($logger instanceof LogManager) {
            $logger = $logger->channel(
                $config['channel'] ?? $this->app->get(ConfigInterface::class)->get('mail.log_channel')
            );
        }

        return new LogTransport($logger);
    }

    /**
     * Create an instance of the Array Transport Driver.
     */
    protected function createArrayTransport(): ArrayTransport
    {
        return new ArrayTransport();
    }

    /**
     * Get a configured Symfony HTTP client instance.
     *
     * @phpstan-ignore-next-line
     */
    protected function getHttpClient(array $config): ?HttpClientInterface
    {
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
            $mailer->{'always' . Str::studly($type)}($address['address'], $address['name']);
        }
    }

    /**
     * Get the mail connection configuration.
     */
    protected function getConfig(string $name): array
    {
        // Here we will check if the "driver" key exists and if it does we will use
        // the entire mail configuration file as the "driver" config in order to
        // provide "BC" for any Laravel <= 6.x style mail configuration files.
        $config = $this->config->get('mail.driver')
            ? $this->config->get('mail')
            : $this->config->get("mail.mailers.{$name}");

        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser())->parseConfiguration($config));

            $config['transport'] = Arr::pull($config, 'driver');
        }

        return $config;
    }

    /**
     * Get the default mail driver name.
     */
    public function getDefaultDriver(): string
    {
        // Here we will check if the "driver" key exists and if it does we will use
        // that as the default driver in order to provide support for old styles
        // of the Laravel mail configuration file for backwards compatibility.
        return $this->config->get('mail.driver')
            ?? $this->config->get('mail.default');
    }

    /**
     * Set the default mail driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        if ($this->config->get('mail.driver')) {
            $this->config->set('mail.driver', $name);
        }

        $this->config->set('mail.default', $name);
    }

    /**
     * Disconnect the given mailer and remove from local cache.
     */
    public function purge(?string $name = null): void
    {
        $name = $name ?: $this->getDefaultDriver();

        unset($this->mailers[$name]);
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
     * Get the application instance used by the manager.
     */
    public function getApplication(): ContainerInterface
    {
        return $this->app;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(ContainerInterface $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Forget all of the resolved mailer instances.
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
