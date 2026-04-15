<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

class ChannelListCommandTest extends \Hypervel\Testbench\TestCase
{
    public function testDoesNotWarnAboutBroadcastServiceProvider()
    {
        $this->artisan('channel:list')
            ->doesntExpectOutputToContain('BroadcastServiceProvider')
            ->assertSuccessful();
    }

    public function testOutputsErrorWhenNoChannelsRegistered()
    {
        $this->artisan('channel:list')
            ->expectsOutputToContain("Your application doesn't have any private broadcasting channels.")
            ->assertSuccessful();
    }
}
