<?php

declare(strict_types=1);

namespace Hypervel\Encryption;

use Hypervel\Encryption\Commands\KeyGenerateCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;

class EncryptionServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerEncrypter();
        $this->registerSerializableClosureSecurityKey();

        $this->commands([
            KeyGenerateCommand::class,
        ]);
    }

    /**
     * Register the encrypter.
     */
    protected function registerEncrypter(): void
    {
        $this->app->singleton('encrypter', function ($app) {
            $config = $app->make('config')->get('app');

            return (new Encrypter($this->parseKey($config), $config['cipher']))
                ->previousKeys(array_map(
                    fn ($key) => $this->parseKey(['key' => $key]),
                    $config['previous_keys'] ?? []
                ));
        });
    }

    /**
     * Configure Serializable Closure signing for security.
     */
    protected function registerSerializableClosureSecurityKey(): void
    {
        $config = $this->app->make('config')->get('app');

        if (! class_exists(SerializableClosure::class) || empty($config['key'])) {
            return;
        }

        SerializableClosure::setSecretKey($this->parseKey($config));
    }

    /**
     * Parse the encryption key.
     */
    protected function parseKey(array $config): string
    {
        if (Str::startsWith($key = $this->key($config), $prefix = 'base64:')) {
            $key = base64_decode(Str::after($key, $prefix));
        }

        return $key;
    }

    /**
     * Extract the encryption key from the given configuration.
     *
     * @throws \Hypervel\Encryption\MissingAppKeyException
     */
    protected function key(array $config): string
    {
        return tap($config['key'], function ($key) {
            if (empty($key)) {
                throw new MissingAppKeyException;
            }
        });
    }
}
