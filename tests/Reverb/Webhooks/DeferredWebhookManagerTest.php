<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\DeferredWebhookManager;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class DeferredWebhookManagerTest extends ReverbTestCase
{
    protected DeferredWebhookManager $manager;

    protected Application $testApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new DeferredWebhookManager;
        $this->testApp = new Application(
            'test-app',
            'test-key',
            'test-secret',
            60,
            30,
            ['*'],
            10_000,
            webhooks: [
                'url' => 'https://example.com/webhook',
                'events' => ['channel_vacated', 'member_removed'],
            ],
        );
    }

    // ── Channel vacated ───────────────────────────────────────────────

    public function testChannelVacatedFiresAfterDelay()
    {
        Queue::fake();

        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('getSubscriptionCount')
            ->with('test-app', 'test-channel')
            ->andReturn(0);
        $sharedState->shouldReceive('clearSmoothingPending')
            ->with('test-app', 'test-channel', 5000)
            ->once();
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferChannelVacated($this->testApp, 'test-channel', 0.05, 5000);

        usleep(80_000); // 80ms — past the 50ms delay

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_vacated'
                && $job->payload->events[0]['channel'] === 'test-channel';
        });
    }

    public function testChannelVacatedSuppressedOnCancel()
    {
        Queue::fake();

        $this->manager->deferChannelVacated($this->testApp, 'test-channel', 0.05, 5000);

        // Cancel before the delay expires
        $this->manager->cancelChannelVacated('test-app', 'test-channel');

        usleep(80_000);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testChannelVacatedSuppressedWhenReOccupied()
    {
        Queue::fake();

        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('getSubscriptionCount')
            ->with('test-app', 'test-channel')
            ->andReturn(1); // Client reconnected
        $sharedState->shouldReceive('clearSmoothingPending')
            ->with('test-app', 'test-channel', 5000)
            ->once();
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferChannelVacated($this->testApp, 'test-channel', 0.05, 5000);

        usleep(80_000);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    // ── Member removed ────────────────────────────────────────────────

    public function testMemberRemovedFiresAfterDelay()
    {
        Queue::fake();

        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('getUserSubscriptionCount')
            ->with('test-app', 'presence-test', 'user-1')
            ->andReturn(0);
        $sharedState->shouldReceive('clearMemberSmoothingPending')
            ->with('test-app', 'presence-test', 'user-1', 5000)
            ->once();
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferMemberRemoved($this->testApp, 'presence-test', 'user-1', 0.05, 5000);

        usleep(80_000);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'member_removed'
                && $event['channel'] === 'presence-test'
                && $event['user_id'] === 'user-1';
        });
    }

    public function testMemberRemovedSuppressedOnCancel()
    {
        Queue::fake();

        $this->manager->deferMemberRemoved($this->testApp, 'presence-test', 'user-1', 0.05, 5000);

        $this->manager->cancelMemberRemoved('test-app', 'presence-test', 'user-1');

        usleep(80_000);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testMemberRemovedSuppressedWhenUserReturned()
    {
        Queue::fake();

        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('getUserSubscriptionCount')
            ->with('test-app', 'presence-test', 'user-1')
            ->andReturn(1); // User reconnected
        $sharedState->shouldReceive('clearMemberSmoothingPending')
            ->with('test-app', 'presence-test', 'user-1', 5000)
            ->once();
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferMemberRemoved($this->testApp, 'presence-test', 'user-1', 0.05, 5000);

        usleep(80_000);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    // ── Cancel all ────────────────────────────────────────────────────

    public function testCancelAllClearsAllPendingTimers()
    {
        Queue::fake();

        $this->manager->deferChannelVacated($this->testApp, 'channel-a', 0.05, 5000);
        $this->manager->deferChannelVacated($this->testApp, 'channel-b', 0.05, 5000);
        $this->manager->deferMemberRemoved($this->testApp, 'presence-c', 'user-1', 0.05, 5000);

        $this->manager->cancelAll();

        usleep(80_000);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }
}
