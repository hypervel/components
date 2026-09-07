<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Queue\Connectors\BeanstalkdConnector;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Timeout;

class QueueBeanstalkdConnectorTest extends TestCase
{
    public function testOmittedAndNullTimeoutUsePheanstalkDefault(): void
    {
        $expected = $this->connectionTimeout(Pheanstalk::create('localhost', 11300));

        foreach ([[], ['timeout' => null]] as $override) {
            $queue = (new BeanstalkdConnector)->connect([
                ...$this->config(),
                ...$override,
            ]);

            $this->assertEquals($expected, $this->connectionTimeout($queue->getPheanstalk()));
        }
    }

    public function testConfiguredTimeoutUsesWholeSeconds(): void
    {
        $queue = (new BeanstalkdConnector)->connect([
            ...$this->config(),
            'timeout' => 7,
        ]);

        $timeout = $this->connectionTimeout($queue->getPheanstalk());

        $this->assertSame(7, $timeout->seconds);
        $this->assertSame(0, $timeout->microSeconds);
    }

    private function config(): array
    {
        return [
            'host' => 'localhost',
            'port' => 11300,
            'queue' => 'default',
        ];
    }

    private function connectionTimeout(Pheanstalk $pheanstalk): Timeout
    {
        $connection = (new ClassInvoker($pheanstalk))->connection;
        $factory = (new ClassInvoker($connection))->factory;

        return (new ClassInvoker($factory))->connectTimeout;
    }
}
