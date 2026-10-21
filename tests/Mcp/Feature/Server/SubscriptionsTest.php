<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Server;

use Hypervel\Tests\Mcp\Fixtures\ArrayTransport;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\TestCase;

use function Hypervel\Tests\Mcp\Fixtures\protocolMeta;

class SubscriptionsTest extends TestCase
{
    /**
     * Collect the subscription responses.
     */
    private function listenMessages(array $notifications): array
    {
        $transport = new ArrayTransport;
        $server = new ExampleServer($transport);

        $server->start();

        ($transport->handler)((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'subscriptions/listen',
            'params' => [
                '_meta' => protocolMeta(),
                'notifications' => $notifications,
            ],
        ]));

        return array_map(fn (string $sent): array => json_decode($sent, true), $transport->sent);
    }

    public function testAcknowledgesAnEmptyFilterFirstAndCarriesTheSubscriptionId(): void
    {
        $messages = $this->listenMessages(['toolsListChanged' => true]);

        $this->assertSame('notifications/subscriptions/acknowledged', $messages[0]['method']);
        $this->assertSame(7, $messages[0]['params']['_meta']['io.modelcontextprotocol/subscriptionId']);
        $this->assertSame([], $messages[0]['params']['notifications']);
    }

    public function testClosesTheSubscriptionImmediatelyWithAnEmptyResult(): void
    {
        $messages = $this->listenMessages(['toolsListChanged' => true, 'resourceSubscriptions' => ['file://a']]);

        $this->assertCount(2, $messages);

        $last = end($messages);

        $this->assertSame(7, $last['id']);
        $this->assertSame(7, $last['result']['_meta']['io.modelcontextprotocol/subscriptionId']);
    }
}
