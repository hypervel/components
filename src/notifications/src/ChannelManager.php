<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Closure;
use Hypervel\Context\Context;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Notifications\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Notifications\Factory as FactoryContract;
use Hypervel\Notifications\Channels\BroadcastChannel;
use Hypervel\Notifications\Channels\DatabaseChannel;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Channels\SlackNotificationRouterChannel;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Support\Manager;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;

class ChannelManager extends Manager implements DispatcherContract, FactoryContract
{
    use HasPoolProxy;

    /**
     * The default channel used to deliver messages.
     */
    protected string $defaultChannel = 'mail';

    /**
     * The locale used when sending notifications.
     */
    protected ?string $locale = null;

    /**
     * The pool proxy class.
     */
    protected string $poolProxyClass = NotificationPoolProxy::class;

    /**
     * The array of drivers which will be wrapped as pool proxies.
     */
    protected array $poolables = ['slack'];

    /**
     * The array of pool config for drivers.
     */
    protected array $poolConfig = [];

    /**
     * Send the given notification to the given notifiable entities.
     */
    public function send(mixed $notifiables, mixed $notification): void
    {
        (new NotificationSender(
            $this,
            $this->container->get(BusDispatcherContract::class),
            $this->container->get(EventDispatcherInterface::class),
            $this->getLocale()
        ))->send($notifiables, $notification);
    }

    /**
     * Send the given notification immediately.
     */
    public function sendNow(mixed $notifiables, mixed $notification, ?array $channels = null): void
    {
        (new NotificationSender(
            $this,
            $this->container->get(BusDispatcherContract::class),
            $this->container->get(EventDispatcherInterface::class),
            $this->getLocale()
        ))->sendNow($notifiables, $notification, $channels);
    }

    /**
     * Get a channel instance.
     */
    public function channel(?string $name = null): mixed
    {
        return $this->driver($name);
    }

    /**
     * Create an instance of the database driver.
     */
    protected function createDatabaseDriver(): DatabaseChannel
    {
        return $this->container->get(DatabaseChannel::class);
    }

    /**
     * Create an instance of the broadcast driver.
     */
    protected function createBroadcastDriver(): BroadcastChannel
    {
        return $this->container->get(BroadcastChannel::class);
    }

    /**
     * Create an instance of the mail driver.
     */
    protected function createMailDriver(): MailChannel
    {
        return $this->container->get(MailChannel::class);
    }

    /**
     * Create an instance of the slack driver.
     */
    protected function createSlackDriver(): SlackNotificationRouterChannel
    {
        return $this->container->get(SlackNotificationRouterChannel::class);
    }

    /**
     * Create a new driver instance.
     *
     * @throws InvalidArgumentException
     */
    protected function createDriver(string $driver): mixed
    {
        $poolConfig = $this->getPoolConfig($driver);
        $hasPool = in_array($driver, $this->poolables);
        if (isset($this->customCreators[$driver])) {
            if ($hasPool) {
                return $this->createPoolProxy(
                    $driver,
                    fn () => $this->callCustomCreator($driver),
                    $poolConfig
                );
            }
            return $this->callCustomCreator($driver);
        }

        $method = 'create' . Str::studly($driver) . 'Driver';

        if (! method_exists($this, $method)) {
            if (class_exists($driver)) {
                return $this->container->get($driver);
            }

            throw new InvalidArgumentException("Driver [{$driver}] is not supported.");
        }

        if ($hasPool) {
            return $this->createPoolProxy(
                $driver,
                fn () => $this->{$method}(),
                $poolConfig
            );
        }

        return $this->{$method}();
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

        return parent::extend($driver, $callback);
    }

    /**
     * Register pool config for custom driver.
     *
     * @return $this
     */
    public function setPoolConfig(string $driver, array $config): static
    {
        $this->poolConfig[$driver] = $config;

        return $this;
    }

    /**
     * Get pool config for custom driver.
     */
    public function getPoolConfig(string $driver): array
    {
        return $this->poolConfig[$driver] ?? [];
    }

    /**
     * Get the default channel driver name.
     */
    public function getDefaultDriver(): string
    {
        return Context::get('__notifications.default_channel', $this->defaultChannel);
    }

    /**
     * Get the default channel driver name.
     */
    public function deliversVia(): string
    {
        return $this->getDefaultDriver();
    }

    /**
     * Set the default channel driver name.
     */
    public function deliverVia(string $channel): void
    {
        Context::set('__notifications.default_channel', $channel);
    }

    /**
     * Set the locale of notifications.
     */
    public function locale(string $locale): static
    {
        Context::set('__notifications.default_locale', $locale);

        return $this;
    }

    /**
     * Get the locale of notifications.
     */
    public function getLocale(): ?string
    {
        return Context::get('__notifications.default_locale', $this->locale);
    }
}
