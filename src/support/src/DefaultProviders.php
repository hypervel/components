<?php

declare(strict_types=1);

namespace Hypervel\Support;

class DefaultProviders
{
    /**
     * The current providers.
     */
    protected array $providers;

    /**
     * Create a new default provider collection.
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?: [
            \Hypervel\Auth\AuthServiceProvider::class,
            \Hypervel\Broadcasting\BroadcastServiceProvider::class,
            \Hypervel\Bus\BusServiceProvider::class,
            \Hypervel\Cache\CacheServiceProvider::class,
            \Hypervel\Console\ConsoleServiceProvider::class,
            \Hypervel\Cookie\CookieServiceProvider::class,
            \Hypervel\Database\DatabaseServiceProvider::class,
            \Hypervel\Devtool\DevtoolServiceProvider::class,
            \Hypervel\Encryption\EncryptionServiceProvider::class,
            \Hypervel\Engine\EngineServiceProvider::class,
            \Hypervel\ExceptionHandler\ExceptionHandlerServiceProvider::class,
            \Hypervel\Filesystem\FilesystemServiceProvider::class,
            \Hypervel\Foundation\Providers\FoundationServiceProvider::class,
            \Hypervel\Hashing\HashingServiceProvider::class,
            \Hypervel\Http\HttpServiceProvider::class,
            \Hypervel\HttpMessage\HttpMessageServiceProvider::class,
            \Hypervel\JWT\JWTServiceProvider::class,
            \Hypervel\Log\LogServiceProvider::class,
            \Hypervel\Foundation\Providers\FormRequestServiceProvider::class,
            \Hypervel\Mail\MailServiceProvider::class,
            \Hypervel\Notifications\NotificationServiceProvider::class,
            \Hypervel\ObjectPool\ObjectPoolServiceProvider::class,
            \Hypervel\Pagination\PaginationServiceProvider::class,
            \Hypervel\Queue\QueueServiceProvider::class,
            \Hypervel\Redis\RedisServiceProvider::class,
            \Hypervel\Router\RouterServiceProvider::class,
            \Hypervel\Serializer\SerializerServiceProvider::class,
            \Hypervel\Server\ServerServiceProvider::class,
            \Hypervel\ServerProcess\ServerProcessServiceProvider::class,
            \Hypervel\Session\SessionServiceProvider::class,
            \Hypervel\Socialite\SocialiteServiceProvider::class,
            \Hypervel\Translation\TranslationServiceProvider::class,
            \Hypervel\Validation\ValidationServiceProvider::class,
            \Hypervel\WebSocketServer\WebSocketServerServiceProvider::class,
            \Hypervel\Pipeline\PipelineServiceProvider::class,
            \Hypervel\View\ViewServiceProvider::class,
        ];
    }

    /**
     * Merge the given providers into the provider collection.
     */
    public function merge(array $providers): static
    {
        $this->providers = array_merge($this->providers, $providers);

        return new static($this->providers);
    }

    /**
     * Replace the given providers with other providers.
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
     */
    public function except(array $providers): static
    {
        return new static((new Collection($this->providers))
            ->reject(fn ($p) => in_array($p, $providers))
            ->values()
            ->toArray());
    }

    /**
     * Convert the provider collection to an array.
     */
    public function toArray(): array
    {
        return $this->providers;
    }
}
