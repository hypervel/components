<?php

declare(strict_types=1);

namespace Hypervel\Queue\Connectors;

use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\BeanstalkdQueue;
use Hypervel\Support\Arr;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Timeout;

class BeanstalkdConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config): Queue
    {
        return new BeanstalkdQueue(
            $this->pheanstalk($config),
            $config['queue'],
            $config['retry_after'],
            $config['block_for'],
            Arr::get($config, 'after_commit', true)
        );
    }

    /**
     * Create a Pheanstalk instance.
     */
    protected function pheanstalk(array $config): Pheanstalk
    {
        $timeout = $config['timeout'] ?? null;

        return Pheanstalk::create(
            $config['host'],
            $config['port'],
            $timeout === null ? null : new Timeout($timeout),
        );
    }
}
