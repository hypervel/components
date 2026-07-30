<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\UserConnectionTerminator;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use RuntimeException;

class UserConnectionTerminatorTest extends ReverbTestCase
{
    public function testTerminatesEveryMatchingConnectionAndPreservesTheFirstFailure(): void
    {
        $application = $this->app->make(ApplicationProvider::class)->all()->first();
        $first = new FailingUserTerminationConnection('first');
        $second = new FakeConnection('second');
        $other = new FakeConnection('other');

        $this->subscribeConnection('presence-users', [
            'user_id' => 'matching',
            'user_info' => [],
        ], $first);
        $this->subscribeConnection('presence-users', [
            'user_id' => 'matching',
            'user_info' => [],
        ], $second);
        $this->subscribeConnection('presence-users', [
            'user_id' => 'other',
            'user_info' => [],
        ], $other);

        try {
            (new UserConnectionTerminator(
                $this->app->make(ChannelManager::class),
            ))->terminate($application, 'matching');
            $this->fail('Expected the first matching disconnect to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('First disconnect failed.', $exception->getMessage());
        }

        $this->assertTrue($first->wasTerminated);
        $second->assertHasBeenTerminated();
        $this->assertFalse($other->wasTerminated);
    }
}

class FailingUserTerminationConnection extends FakeConnection
{
    public function terminate(?int $code = null, ?string $reason = null): void
    {
        parent::terminate($code, $reason);

        throw new RuntimeException('First disconnect failed.');
    }
}
