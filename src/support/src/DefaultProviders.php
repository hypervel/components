<?php

declare(strict_types=1);

namespace Hypervel\Support;

/**
 * Core framework service providers loaded for every Hypervel application.
 *
 * Only providers that are part of the framework itself belong here — services
 * that every app needs regardless of what it does (database, cache, session,
 * encryption, validation, etc.) plus Swoole infrastructure (engine, server,
 * object-pool, signal).
 *
 * Optional/standalone packages (Reverb, Scout, Horizon, Sanctum, etc.) must
 * not be added here. They register their own providers via composer.json
 * extra.hypervel.providers and are auto-discovered when installed. Tests for
 * these packages register the provider via the test class's
 * getPackageProviders() method.
 */
class DefaultProviders
{
    /**
     * The current providers.
     *
     * @var array<class-string>
     */
    protected array $providers;

    /**
     * Create a new default provider collection.
     *
     * @param null|array<class-string> $providers
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? [
            \Hypervel\Auth\AuthServiceProvider::class,
            \Hypervel\Auth\Passwords\PasswordResetServiceProvider::class,
            \Hypervel\Broadcasting\BroadcastServiceProvider::class,
            \Hypervel\Bus\BusServiceProvider::class,
            \Hypervel\Cache\CacheServiceProvider::class,
            \Hypervel\Concurrency\ConcurrencyServiceProvider::class,
            \Hypervel\Console\ConsoleServiceProvider::class,
            \Hypervel\Cookie\CookieServiceProvider::class,
            \Hypervel\Database\DatabaseServiceProvider::class,
            \Hypervel\Encryption\EncryptionServiceProvider::class,
            \Hypervel\Engine\EngineServiceProvider::class,
            \Hypervel\Filesystem\FilesystemServiceProvider::class,
            \Hypervel\Foundation\Providers\FormRequestServiceProvider::class,
            \Hypervel\Foundation\Providers\FoundationServiceProvider::class,
            \Hypervel\Hashing\HashingServiceProvider::class,
            \Hypervel\Http\HttpServiceProvider::class,
            \Hypervel\Mail\MailServiceProvider::class,
            \Hypervel\Notifications\NotificationServiceProvider::class,
            \Hypervel\ObjectPool\ObjectPoolServiceProvider::class,
            \Hypervel\Pagination\PaginationServiceProvider::class,
            \Hypervel\Pipeline\PipelineServiceProvider::class,
            \Hypervel\Queue\QueueServiceProvider::class,
            \Hypervel\RateLimiter\RateLimiterServiceProvider::class,
            \Hypervel\Redis\RedisServiceProvider::class,
            \Hypervel\Server\ServerServiceProvider::class,
            \Hypervel\ServerProcess\ServerProcessServiceProvider::class,
            \Hypervel\Session\SessionServiceProvider::class,
            \Hypervel\Signal\SignalServiceProvider::class,
            \Hypervel\Translation\TranslationServiceProvider::class,
            \Hypervel\Validation\ValidationServiceProvider::class,
            \Hypervel\View\ViewServiceProvider::class,
        ];
    }

    /**
     * Merge the given providers into the provider collection.
     *
     * @param array<class-string> $providers
     */
    public function merge(array $providers): static
    {
        return new static(array_merge($this->providers, $providers));
    }

    /**
     * Replace the given providers with other providers.
     *
     * @param array<class-string, class-string> $replacements
     */
    public function replace(array $replacements): static
    {
        $current = new Collection($this->providers);

        foreach ($replacements as $from => $to) {
            $key = $current->search($from);

            $current = is_int($key) ? $current->replace([$key => $to]) : $current;
        }

        return new static($current->values()->toArray());
    }

    /**
     * Disable the given providers.
     *
     * @param array<class-string> $providers
     */
    public function except(array $providers): static
    {
        return new static((new Collection($this->providers))
            ->diff($providers)
            ->values()
            ->toArray());
    }

    /**
     * Convert the provider collection to an array.
     *
     * @return array<class-string>
     */
    public function toArray(): array
    {
        return $this->providers;
    }
}
