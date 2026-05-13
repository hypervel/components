<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\SqsQueue;
use Hypervel\Tests\TestCase;

class QueueSqsConnectorTest extends TestCase
{
    public function testConnectSucceedsWithoutAfterCommitConfig()
    {
        $connector = new SqsConnector;

        $queue = $connector->connect([
            'queue' => 'default',
            'region' => 'us-east-1',
        ]);

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }
}
