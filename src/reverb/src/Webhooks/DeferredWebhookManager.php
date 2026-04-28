<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks;

use Hypervel\Coordinator\Timer;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;

class DeferredWebhookManager
{
    /**
     * Pending timer IDs keyed by "{type}:{appId}:{channel}[:{userId}]".
     *
     * @var array<string, int>
     */
    protected array $pending = [];

    /**
     * Whether the manager is in draining mode (worker shutting down).
     */
    protected bool $draining = false;

    protected Timer $timer;

    /**
     * Create a new deferred webhook manager instance.
     */
    public function __construct()
    {
        $this->timer = new Timer;
    }

    /**
     * Schedule a deferred channel_vacated webhook.
     */
    public function deferChannelVacated(Application $app, string $channel, float $delaySeconds, int $smoothingTtlMs): void
    {
        $key = "vacated:{$app->id()}:{$channel}";

        $this->cancel($key);

        $this->pending[$key] = $this->timer->after($delaySeconds, function () use ($app, $channel, $key, $smoothingTtlMs) {
            unset($this->pending[$key]);

            $sharedState = app(SharedState::class);

            try {
                if ($sharedState->getSubscriptionCount($app->id(), $channel) > 0) {
                    return;
                }

                app(WebhookDispatcher::class)->dispatch($app, 'channel_vacated', [
                    'channel' => $channel,
                ]);
            } finally {
                $sharedState->clearSmoothingPending($app->id(), $channel, $smoothingTtlMs);
            }
        });
    }

    /**
     * Schedule a deferred member_removed webhook.
     */
    public function deferMemberRemoved(Application $app, string $channel, string $userId, float $delaySeconds, int $smoothingTtlMs): void
    {
        $key = "member_removed:{$app->id()}:{$channel}:{$userId}";

        $this->cancel($key);

        $this->pending[$key] = $this->timer->after($delaySeconds, function () use ($app, $channel, $userId, $key, $smoothingTtlMs) {
            unset($this->pending[$key]);

            $sharedState = app(SharedState::class);

            try {
                if ($sharedState->getUserSubscriptionCount($app->id(), $channel, $userId) > 0) {
                    return;
                }

                app(WebhookDispatcher::class)->dispatch($app, 'member_removed', [
                    'channel' => $channel,
                    'user_id' => $userId,
                ]);
            } finally {
                $sharedState->clearMemberSmoothingPending($app->id(), $channel, $userId, $smoothingTtlMs);
            }
        });
    }

    /**
     * Cancel a pending deferred channel_vacated webhook.
     *
     * Returns true if a pending timer was cancelled (same-worker fast path).
     */
    public function cancelChannelVacated(string $appId, string $channel): bool
    {
        return $this->cancel("vacated:{$appId}:{$channel}");
    }

    /**
     * Cancel a pending deferred member_removed webhook.
     *
     * Returns true if a pending timer was cancelled (same-worker fast path).
     */
    public function cancelMemberRemoved(string $appId, string $channel, string $userId): bool
    {
        return $this->cancel("member_removed:{$appId}:{$channel}:{$userId}");
    }

    /**
     * Cancel all pending deferred webhook timers without firing them.
     *
     * NOT called during shutdown — pending timers fire naturally via the
     * WORKER_EXIT coordinator. This method exists for testing cleanup.
     */
    public function cancelAll(): void
    {
        foreach (array_keys($this->pending) as $key) {
            $this->timer->clear($this->pending[$key]);
        }

        $this->pending = [];
    }

    /**
     * Enable draining mode (worker shutting down).
     *
     * When draining, callers should fire webhooks immediately
     * instead of deferring them.
     */
    public function setDraining(bool $draining): void
    {
        $this->draining = $draining;
    }

    /**
     * Determine whether the manager is in draining mode.
     */
    public function isDraining(): bool
    {
        return $this->draining;
    }

    /**
     * Cancel a pending timer by key.
     */
    protected function cancel(string $key): bool
    {
        if (isset($this->pending[$key])) {
            $this->timer->clear($this->pending[$key]);
            unset($this->pending[$key]);

            return true;
        }

        return false;
    }
}
