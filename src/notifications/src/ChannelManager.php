<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Notifications\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Notifications\Factory as FactoryContract;
use Hypervel\Notifications\Channels\BroadcastChannel;
use Hypervel\Notifications\Channels\DatabaseChannel;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Channels\SlackNotificationRouterChannel;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Support\Manager;
use Hypervel\Support\Queue\Concerns\ResolvesQueueRoutes;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;

class ChannelManager extends Manager implements DispatcherContract, FactoryContract
{
    use HasPoolProxy;
    use Macroable;
    use ResolvesQueueRoutes;

    /**
     * Context key for the per-request default channel override.
     */
    protected const DEFAULT_CHANNEL_CONTEXT_KEY = '__notifications.default_channel';

    /**
     * Context key for the per-request default locale override.
     */
    protected const DEFAULT_LOCALE_CONTEXT_KEY = '__notifications.default_locale';

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
            $this->container->make(BusDispatcherContract::class),
            $this->container->make(EventDispatcher::class),
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
            $this->container->make(BusDispatcherContract::class),
            $this->container->make(EventDispatcher::class),
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
        return $this->container->make(DatabaseChannel::class);
    }

    /**
     * Create an instance of the broadcast driver.
     */
    protected function createBroadcastDriver(): BroadcastChannel
    {
        return $this->container->make(BroadcastChannel::class);
    }

    /**
     * Create an instance of the mail driver.
     */
    protected function createMailDriver(): MailChannel
    {
        return $this->container->make(MailChannel::class);
    }

    /**
     * Create an instance of the slack driver.
     */
    protected function createSlackDriver(): SlackNotificationRouterChannel
    {
        return $this->container->make(SlackNotificationRouterChannel::class);
    }

    /**
     * Create a new driver instance.
     *
     * @throws InvalidArgumentException
     */
    protected function createDriver(string $driver): mixed
    {
        $hasPool = in_array($driver, $this->poolables);
        $poolConfig = $this->getPoolConfig($driver);

        try {
            if ($hasPool) {
                return $this->createPoolProxy(
                    $driver,
                    fn () => parent::createDriver($driver),
                    $poolConfig
                );
            }

            return parent::createDriver($driver);
        } catch (InvalidArgumentException $e) {
            if (class_exists($driver)) {
                return $this->container->make($driver);
            }

            throw $e;
        }
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
        return CoroutineContext::get(self::DEFAULT_CHANNEL_CONTEXT_KEY, $this->defaultChannel);
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
        CoroutineContext::set(self::DEFAULT_CHANNEL_CONTEXT_KEY, $channel);
    }

    /**
     * Set the locale of notifications.
     */
    public function locale(string $locale): static
    {
        CoroutineContext::set(self::DEFAULT_LOCALE_CONTEXT_KEY, $locale);

        return $this;
    }

    /**
     * Get the locale of notifications.
     */
    public function getLocale(): ?string
    {
        return CoroutineContext::get(self::DEFAULT_LOCALE_CONTEXT_KEY, $this->locale);
    }
}
