<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks;

use Closure;
use Hypervel\Coordinator\Timer;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\DeferredWebhookManager;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;
use ReflectionProperty;

class DeferredWebhookManagerTest extends ReverbTestCase
{
    protected DeferredWebhookManager $manager;

    protected Application $testApp;

    /**
     * Set up the deferred webhook manager.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new DeferredWebhookManager;
        $webhooks = array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_vacated', 'member_removed'],
        ]);
        $this->testApp = new Application(
            'test-app',
            'test-key',
            'test-secret',
            60,
            30,
            ['*'],
            10_000,
            webhooks: $webhooks,
        );
    }

    // ── Channel vacated ───────────────────────────────────────────────

    public function testChannelVacatedFiresAfterDelay(): void
    {
        Queue::fake();
        $callback = null;
        $this->captureDeferredCallback(10, $callback);

        $sharedState = Double::for(SharedState::class)->strict();
        $sharedState->allows('getSubscriptionCount')
            ->with('test-app', 'test-channel')
            ->returns(0);
        $sharedState->expects('clearSmoothingPending')
            ->with('test-app', 'test-channel', 5000);
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferChannelVacated($this->testApp, 'test-channel', 0.05, 5000);

        $this->assertInstanceOf(Closure::class, $callback);
        $callback(false);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_vacated'
                && $job->payload->events[0]['channel'] === 'test-channel';
        });
    }

    public function testChannelVacatedSuppressedOnCancel(): void
    {
        $timer = $this->replaceTimer();
        $timer->expects('after')->returns(11);
        $timer->expects('clear')->with(11);

        $this->manager->deferChannelVacated($this->testApp, 'test-channel', 0.05, 5000);

        $this->assertTrue($this->manager->cancelChannelVacated('test-app', 'test-channel'));
        $this->assertFalse($this->manager->cancelChannelVacated('test-app', 'test-channel'));
    }

    public function testChannelVacatedSuppressedWhenReOccupied(): void
    {
        Queue::fake();
        $callback = null;
        $this->captureDeferredCallback(13, $callback);

        $sharedState = Double::for(SharedState::class)->strict();
        $sharedState->allows('getSubscriptionCount')
            ->with('test-app', 'test-channel')
            ->returns(1); // Client reconnected
        $sharedState->expects('clearSmoothingPending')
            ->with('test-app', 'test-channel', 5000);
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferChannelVacated($this->testApp, 'test-channel', 0.05, 5000);

        $this->assertInstanceOf(Closure::class, $callback);
        $callback(false);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
        $this->assertFalse($this->manager->cancelChannelVacated('test-app', 'test-channel'));
    }

    // ── Member removed ────────────────────────────────────────────────

    public function testMemberRemovedFiresAfterDelay(): void
    {
        Queue::fake();
        $callback = null;
        $this->captureDeferredCallback(14, $callback);

        $sharedState = Double::for(SharedState::class)->strict();
        $sharedState->allows('getUserSubscriptionCount')
            ->with('test-app', 'presence-test', 'user-1')
            ->returns(0);
        $sharedState->expects('clearMemberSmoothingPending')
            ->with('test-app', 'presence-test', 'user-1', 5000);
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferMemberRemoved($this->testApp, 'presence-test', 'user-1', 0.05, 5000);

        $this->assertInstanceOf(Closure::class, $callback);
        $callback(false);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'member_removed'
                && $event['channel'] === 'presence-test'
                && $event['user_id'] === 'user-1';
        });
    }

    public function testMemberRemovedSuppressedOnCancel(): void
    {
        $timer = $this->replaceTimer();
        $timer->expects('after')->returns(12);
        $timer->expects('clear')->with(12);

        $this->manager->deferMemberRemoved($this->testApp, 'presence-test', 'user-1', 0.05, 5000);

        $this->assertTrue($this->manager->cancelMemberRemoved('test-app', 'presence-test', 'user-1'));
        $this->assertFalse($this->manager->cancelMemberRemoved('test-app', 'presence-test', 'user-1'));
    }

    public function testMemberRemovedSuppressedWhenUserReturned(): void
    {
        Queue::fake();
        $callback = null;
        $this->captureDeferredCallback(15, $callback);

        $sharedState = Double::for(SharedState::class)->strict();
        $sharedState->allows('getUserSubscriptionCount')
            ->with('test-app', 'presence-test', 'user-1')
            ->returns(1); // User reconnected
        $sharedState->expects('clearMemberSmoothingPending')
            ->with('test-app', 'presence-test', 'user-1', 5000);
        $this->app->instance(SharedState::class, $sharedState);

        $this->manager->deferMemberRemoved($this->testApp, 'presence-test', 'user-1', 0.05, 5000);

        $this->assertInstanceOf(Closure::class, $callback);
        $callback(false);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
        $this->assertFalse($this->manager->cancelMemberRemoved('test-app', 'presence-test', 'user-1'));
    }

    // ── Cancel all ────────────────────────────────────────────────────

    public function testCancelAllClearsAllPendingTimers(): void
    {
        $timer = $this->replaceTimer();
        $timer->expects('after')->times(3)->returns(1, 2, 3);
        $timer->expects('clear')->with(1);
        $timer->expects('clear')->with(2);
        $timer->expects('clear')->with(3);

        $this->manager->deferChannelVacated($this->testApp, 'channel-a', 0.05, 5000);
        $this->manager->deferChannelVacated($this->testApp, 'channel-b', 0.05, 5000);
        $this->manager->deferMemberRemoved($this->testApp, 'presence-c', 'user-1', 0.05, 5000);

        $this->manager->cancelAll();

        $this->assertFalse($this->manager->cancelChannelVacated('test-app', 'channel-a'));
        $this->assertFalse($this->manager->cancelChannelVacated('test-app', 'channel-b'));
        $this->assertFalse($this->manager->cancelMemberRemoved('test-app', 'presence-c', 'user-1'));
    }

    /**
     * Replace the manager's timer for deterministic cancellation assertions.
     */
    protected function replaceTimer(): Timer
    {
        $timer = Double::for(Timer::class)->strict();

        (new ReflectionProperty($this->manager, 'timer'))->setValue($this->manager, $timer);

        return $timer;
    }

    /**
     * Replace the manager's timer and capture its deferred callback.
     */
    protected function captureDeferredCallback(int $timerId, ?Closure &$callback): void
    {
        $timer = $this->replaceTimer();
        $timer->expects('after')
            ->resolves(function (float $timeout, Closure $deferredCallback) use ($timerId, &$callback): int {
                $callback = $deferredCallback;

                return $timerId;
            });
    }
}
