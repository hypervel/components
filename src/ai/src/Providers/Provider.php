<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Contracts\Providers\Provider as ProviderContract;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Container\Container;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Collection;
use Stringable;

abstract class Provider implements Stringable, ProviderContract
{
    /**
     * Create a provider instance.
     */
    public function __construct(
        protected Gateway $gateway,
        protected array $config,
        protected Dispatcher $events
    ) {
    }

    /**
     * Get the name of the underlying AI provider.
     */
    public function name(): string
    {
        return $this->config['name'];
    }

    /**
     * Get the name of the underlying AI driver.
     */
    public function driver(): string
    {
        return $this->config['driver'];
    }

    /**
     * Get the credentials for the underlying AI provider.
     */
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'],
        ];
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    public function additionalConfiguration(): array
    {
        return array_diff_key($this->config, array_flip(['driver', 'key', 'name', 'ondemand']));
    }

    /**
     * Get a provider instance that sends the given HTTP headers with each request.
     *
     * @param array<string, string> $headers
     *
     * @internal
     */
    public function withHeaders(array $headers): static
    {
        if (! filled($headers)) {
            return $this;
        }

        return tap(clone $this, function (self $provider) use ($headers): void {
            $provider->config['headers'] = array_merge($provider->config['headers'] ?? [], $headers);
        });
    }

    /**
     * Format the given provider / model list.
     */
    public static function formatProviderAndModelList(self|Lab|array|string $providers, ?string $model = null): array
    {
        if (! is_array($providers)) {
            return [self::nameOf($providers) => $model];
        }

        return (new Collection($providers))->mapWithKeys(fn (mixed $value, int|string $key): array => is_numeric($key)
            ? [self::nameOf($value) => null]
            : [$key => $value])->all();
    }

    /**
     * Get provider and model pairs without discarding on-demand instances.
     *
     * @internal
     *
     * @return list<array{self|string, ?string}>
     */
    public static function providerAndModelPairs(self|Lab|array|string $providers, ?string $model = null): array
    {
        if (! is_array($providers)) {
            return [[$providers instanceof Lab ? $providers->value : $providers, $model]];
        }

        $pairs = [];

        foreach ($providers as $key => $value) {
            $pairs[] = is_numeric($key)
                ? [$value instanceof Lab ? $value->value : $value, null]
                : [$key, $value];
        }

        return $pairs;
    }

    /**
     * Get the name the given provider is resolved by.
     */
    private static function nameOf(self|Lab|string $provider): string
    {
        return match (true) {
            $provider instanceof self => $provider->name(),
            $provider instanceof Lab => $provider->value,
            default => $provider,
        };
    }

    /**
     * Get the serializable representation of the provider.
     */
    public function __serialize(): array
    {
        return ($this->config['ondemand'] ?? false)
            ? ['config' => Container::getInstance()->make(Encrypter::class)->encrypt($this->config)]
            : ['name' => $this->name()];
    }

    /**
     * Restore the provider from its serialized representation.
     */
    public function __unserialize(array $data): void
    {
        $manager = Container::getInstance()->make(AiManager::class);

        $provider = isset($data['config'])
            ? $manager->build(Container::getInstance()->make(Encrypter::class)->decrypt($data['config']))
            : $manager->instance($data['name']);

        foreach (get_object_vars($provider) as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * Convert the provider to its string representation.
     */
    public function __toString(): string
    {
        // Configured providers cast to their driver for backward compatibility...
        return ($this->config['ondemand'] ?? false) ? $this->name() : $this->driver();
    }
}
