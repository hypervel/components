<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\LogBroadcaster;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JsonException;
use Psr\Log\LoggerInterface;

class LogBroadcasterTest extends TestCase
{
    use VerifiesDoubles;

    public function testBroadcastUsesFormattedChannelNames(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $logger = Double::for(LoggerInterface::class)->strict();
        $logger->expects('info')
            ->with(Argument::satisfies(
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

        $logger = Double::for(LoggerInterface::class)->strict();
        $logger->expects('info')->never();

        (new LogBroadcaster($logger))->broadcast(
            ['orders'],
            'OrderCreated',
            ['invalid' => NAN],
        );
    }
}
