<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Aws\Sqs\SqsClient;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\Arr;

class SqsConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        $config = $this->getDefaultConfiguration($config);

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);

            if (! empty($config['token'])) {
                $config['credentials']['token'] = $config['token'];
            }
        }

        return new SqsQueue(
            new SqsClient(
                Arr::except($config, ['token'])
            ),
            $config['queue'],
            $config['prefix'] ?? '',
            $config['suffix'] ?? '',
            $config['after_commit'] ?? null
        );
    }

    /**
     * Get the default configuration for SQS.
     */
    protected function getDefaultConfiguration(array $config): array
    {
        return array_merge([
            'version' => 'latest',
            'http' => [
                'timeout' => 60,
                'connect_timeout' => 60,
            ],
        ], $config);
    }
}
