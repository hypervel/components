<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Support\Facades\Broadcast;
use Hypervel\Testbench\TestCase;

class ChannelListCommandTest extends TestCase
{
    public function testDoesNotWarnAboutBroadcastServiceProvider(): void
    {
        $this->artisan('channel:list')
            ->doesntExpectOutputToContain('BroadcastServiceProvider')
            ->assertSuccessful();
    }

    public function testOutputsErrorWhenNoChannelsRegistered(): void
    {
        $this->artisan('channel:list')
            ->expectsOutputToContain("Your application doesn't have any private broadcasting channels.")
            ->assertSuccessful();
    }

    public function testItListsRegisteredChannels(): void
    {
        Broadcast::channel('orders.{order}', fn (): bool => true);

        $this->artisan('channel:list')
            ->expectsOutputToContain('orders.{order}')
            ->expectsOutputToContain('Showing [1] private channels')
            ->assertSuccessful();
    }
}
