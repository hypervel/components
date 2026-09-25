<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Aws\Configuration\ConfigurationResolver;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\EcsCredentialProvider;
use Aws\Credentials\InstanceProfileProvider;
use Aws\Sqs\SqsClient;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\AwsCredentialCache;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\Arr;
use InvalidArgumentException;

class SqsConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        $config = $this->withCredentials(
            $this->getDefaultConfiguration($config)
        );

        return new SqsQueue(
            new SqsClient($this->clientConfiguration($config)),
            $config['queue'],
            $config['prefix'],
            $config['suffix'],
            $config['after_commit'],
            $config['overflow'],
        );
    }

    /**
     * Get the default configuration for SQS.
     */
    protected function getDefaultConfiguration(array $config): array
    {
        return [
            'key' => null,
            'secret' => null,
            'token' => null,
            'credentials' => null,
            'after_commit' => true,
            'overflow' => [],
            'version' => 'latest',
            ...$config,
            // Shipped env-backed values may be null, while SqsQueue requires strings.
            'prefix' => $config['prefix'] ?? '',
            'suffix' => $config['suffix'] ?? '',
            'http' => [
                'timeout' => 60,
                'connect_timeout' => 60,
                ...($config['http'] ?? []),
            ],
            'credential_cache' => [
                'store' => null,
                'fallback_store' => null,
                ...($config['credential_cache'] ?? []),
                'enabled' => (bool) ($config['credential_cache']['enabled'] ?? false),
            ],
        ];
    }

    /**
     * Get the AWS client configuration for the given connection config.
     */
    protected function clientConfiguration(array $config): array
    {
        // The queue token is an AWS session credential, while the SDK's
        // top-level token option is an unrelated bearer token.
        return Arr::except($config, [
            'driver',
            'queue',
            'prefix',
            'suffix',
            'after_commit',
            'key',
            'secret',
            'token',
            'overflow',
            'credential_cache',
        ]);
    }

    /**
     * Configure the credentials for the given connection config.
     *
     * @throws InvalidArgumentException
     */
    protected function withCredentials(array $config): array
    {
        $credentials = $config['credentials'];

        if (($resolvedCredentials = $this->resolveCredentialProvider($config)) !== null) {
            $config['credentials'] = $resolvedCredentials;
        } elseif ($credentials === null && empty($config['key']) !== empty($config['secret'])) {
            throw new InvalidArgumentException('The SQS access key and secret must be configured together.');
        } elseif ($credentials === null && ! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);

            if (! empty($config['token'])) {
                $config['credentials']['token'] = $config['token'];
            }
        } elseif ($credentials === null && $this->credentialCachingEnabled($config)) {
            // The SDK builds its default chain from the client arguments, so
            // the cached chain receives the same configuration.
            $config['credentials'] = CredentialProvider::memoize(
                $this->cachedCredentialProvider(
                    CredentialProvider::defaultProvider($this->clientConfiguration($config)),
                    $config,
                )
            );
        }

        return $config;
    }

    /**
     * Resolve a credential provider from the given config.
     *
     * @throws InvalidArgumentException
     */
    protected function resolveCredentialProvider(array $config): mixed
    {
        $credentials = $config['credentials'];

        $provider = is_array($credentials) ? ($credentials['provider'] ?? null) : $credentials;

        if (! is_string($provider)) {
            return $provider;
        }

        $options = is_array($credentials) ? Arr::except($credentials, ['provider']) : [];

        $resolved = match ($provider) {
            'ecs' => CredentialProvider::ecsCredentials($options),
            'instance' => CredentialProvider::instanceProfile($options),
            default => throw new InvalidArgumentException(
                "Invalid credential provider [{$provider}]."
            ),
        };

        if ($this->credentialCachingEnabled($config)) {
            $resolved = $this->cachedCredentialProvider($resolved, $config);
        }

        return CredentialProvider::memoize($resolved);
    }

    /**
     * Wrap the given credential provider so the credentials it resolves are shared across processes via the cache.
     */
    protected function cachedCredentialProvider(callable $provider, array $config): callable
    {
        [$store, $fallbackStore] = [
            $config['credential_cache']['store'],
            $config['credential_cache']['fallback_store'],
        ];

        $cache = new AwsCredentialCache(
            fn () => Container::getInstance()->make('cache')->store($store),
            $fallbackStore ? fn () => Container::getInstance()->make('cache')->store($fallbackStore) : null,
        );

        return fn () => $cache->resolve(static::credentialsCacheKey($config), $provider);
    }

    /**
     * Get the cache key for the connection's shared credentials.
     *
     * The key is scoped to this host or container, because the role behind
     * instance and container credentials is only known after fetching them,
     * so identical settings on different machines may resolve different
     * roles. Within that scope it separates the configured provider and the
     * environment and configuration values that select its credentials.
     * Secret values are hashed before they reach the key.
     */
    public static function credentialsCacheKey(array $config): string
    {
        $credentials = $config['credentials'] ?? null;

        $provider = is_array($credentials) ? ($credentials['provider'] ?? null) : $credentials;
        $provider = is_string($provider) ? $provider : 'default';

        // Named providers read their own options; the default chain reads the client configuration.
        $options = is_array($credentials) ? $credentials : $config;

        $identity = [
            'host' => gethostname(),
            'provider' => $provider,
            'region' => $config['region'] ?? null,
            'prefix' => $config['prefix'] ?? null,
            'suffix' => $config['suffix'] ?? null,
        ];

        if ($provider !== 'instance') {
            // The SDK falls back to $_SERVER only when getenv() has no value.
            $containerUri = fn (string $name): mixed => ($value = getenv($name)) !== false ? $value : ($_SERVER[$name] ?? '');

            $identity['container'] = [
                'relative_uri' => $containerUri(EcsCredentialProvider::ENV_URI),
                'full_uri' => $containerUri(EcsCredentialProvider::ENV_FULL_URI),
                'token_file' => getenv(EcsCredentialProvider::ENV_AUTH_TOKEN_FILE),
                'token' => hash('sha256', (string) getenv(EcsCredentialProvider::ENV_AUTH_TOKEN)),
            ];
        }

        if ($provider !== 'ecs') {
            $identity['instance'] = [
                'options' => Arr::only($options, [
                    'profile',
                    InstanceProfileProvider::CFG_EC2_METADATA_SERVICE_ENDPOINT,
                    InstanceProfileProvider::CFG_EC2_METADATA_SERVICE_ENDPOINT_MODE,
                ]),
                'endpoint' => ConfigurationResolver::env(InstanceProfileProvider::CFG_EC2_METADATA_SERVICE_ENDPOINT),
                'endpoint_mode' => ConfigurationResolver::env(InstanceProfileProvider::CFG_EC2_METADATA_SERVICE_ENDPOINT_MODE),
                // Matches the SDK's check before each metadata request.
                'disabled' => strcasecmp((string) getenv(InstanceProfileProvider::ENV_DISABLE), 'true') === 0,
            ];

            $identity['shared_config'] = [
                'enabled' => $options['use_aws_shared_config_files'] ?? null,
                'profile' => getenv(CredentialProvider::ENV_PROFILE) ?: 'default',
                'config_file' => CredentialProvider::getConfigFileName(),
            ];
        }

        if ($provider === 'default') {
            $identity['default'] = [
                'environment' => hash('sha256', json_encode([
                    getenv(CredentialProvider::ENV_KEY),
                    getenv(CredentialProvider::ENV_SECRET),
                    getenv(CredentialProvider::ENV_SESSION),
                    getenv(CredentialProvider::ENV_ACCOUNT_ID),
                ], JSON_THROW_ON_ERROR)),
                'credentials_file' => CredentialProvider::getCredentialsFileName(null),
                'web_identity' => [
                    getenv(CredentialProvider::ENV_ARN),
                    getenv(CredentialProvider::ENV_TOKEN_FILE),
                    getenv(CredentialProvider::ENV_ROLE_SESSION_NAME),
                ],
                'config' => Arr::only($config, ['filename', 'preferStaticCredentials', 'disableAssumeRole']),
            ];
        }

        return 'aws:sqs:credentials:' . hash('xxh128', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /**
     * Determine if resolved credentials should be shared across processes via the cache.
     */
    protected function credentialCachingEnabled(array $config): bool
    {
        return $config['credential_cache']['enabled'];
    }
}
