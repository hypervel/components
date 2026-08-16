<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Aws\Credentials\CredentialProvider;
use Aws\Sqs\SqsClient;
use Hypervel\Contracts\Queue\Queue;
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
        $config = $this->getDefaultConfiguration($config);

        $key = $config['key'];
        $secret = $config['secret'];
        $token = $config['token'];
        $credentials = $config['credentials'];
        $suffix = $config['suffix'];

        if (($resolvedCredentials = $this->resolveCredentialProvider($config)) !== null) {
            $config['credentials'] = $resolvedCredentials;
        } elseif ($credentials === null && empty($key) !== empty($secret)) {
            throw new InvalidArgumentException('The SQS access key and secret must be configured together.');
        } elseif ($credentials === null && ! empty($key) && ! empty($secret)) {
            $config['credentials'] = ['key' => $key, 'secret' => $secret];

            if (! empty($token)) {
                $config['credentials']['token'] = $token;
            }
        }

        // The queue token is an AWS session credential, while the SDK's
        // top-level token option is an unrelated bearer token.
        $clientConfig = Arr::except($config, ['token', 'overflow']);

        return new SqsQueue(
            new SqsClient($clientConfig),
            $config['queue'],
            $config['prefix'],
            $suffix ?? '',
            $config['after_commit'],
            $config['overflow'],
        );
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

        return CredentialProvider::memoize($resolved);
    }

    /**
     * Get the default configuration for SQS.
     */
    protected function getDefaultConfiguration(array $config): array
    {
        return [
            'credentials' => null,
            'version' => 'latest',
            ...$config,
            'http' => [
                'timeout' => 60,
                'connect_timeout' => 60,
                ...($config['http'] ?? []),
            ],
        ];
    }
}
