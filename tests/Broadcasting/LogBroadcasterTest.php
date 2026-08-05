<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\LogBroadcaster;
use Hypervel\Tests\TestCase;
use JsonException;
use Mockery as m;
use Psr\Log\LoggerInterface;

class LogBroadcasterTest extends TestCase
{
    public function testBroadcastUsesFormattedChannelNames(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with(m::on(
                static fn (string $message): bool => str_contains(
                    $message,
                    'on channels [application.orders, application.users]',
                ),
            ));

        (new LogBroadcaster($logger))->broadcast(
            ['orders', 'users'],
            'OrderCreated',
            ['id' => 1],
        );
    }

    public function testBroadcastThrowsWhenPayloadCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldNotReceive('info');

        (new LogBroadcaster($logger))->broadcast(
            ['orders'],
            'OrderCreated',
            ['invalid' => NAN],
        );
    }
}
