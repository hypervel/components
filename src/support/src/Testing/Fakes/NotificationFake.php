<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use Exception;
use Hypervel\Support\Collection;
use Hyperf\Macroable\Macroable;
use Hyperf\Stringable\Str;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Hypervel\Contracts\Notifications\Factory as NotificationFactory;
use Hypervel\Support\Traits\ReflectsClosures;
use Hypervel\Contracts\Translation\HasLocalePreference;
use PHPUnit\Framework\Assert as PHPUnit;

class NotificationFake implements Fake, NotificationDispatcher, NotificationFactory
{
    use Macroable;
    use ReflectsClosures;

    /**
     * All of the notifications that have been sent.
     */
    protected array $notifications = [];

    /**
     * Locale used when sending notifications.
     */
    public ?string $locale = null;

    /**
     * Assert if a notification was sent on-demand based on a truth-test callback.
     *
     * @throws Exception
     */
    public function assertSentOnDemand(Closure|string $notification, ?callable $callback = null): void
    {
        $this->assertSentTo(new AnonymousNotifiable(), $notification, $callback);
    }

    /**
     * Assert if a notification was sent based on a truth-test callback.
     *
     * @throws Exception
     */
    public function assertSentTo(mixed $notifiable, Closure|string $notification, callable|int|string|null $callback = null): void
    {
        if (is_array($notifiable) || $notifiable instanceof Collection) {
            if (count($notifiable) === 0) {
                throw new Exception('No notifiable given.');
            }

            foreach ($notifiable as $singleNotifiable) {
                $this->assertSentTo($singleNotifiable, $notification, $callback);
            }

            return;
        }

        if ($notification instanceof Closure) {
            [$notification, $callback] = [$this->firstClosureParameterType($notification), $notification];
        }

        if (is_numeric($callback)) {
            $this->assertSentToTimes($notifiable, $notification, $callback);
            return;
        }

        PHPUnit::assertTrue(
            $this->sent($notifiable, $notification, $callback)->count() > 0,
            "The expected [{$notification}] notification was not sent."
        );
    }

    /**
     * Assert if a notification was sent on-demand a number of times.
     */
    public function assertSentOnDemandTimes(string $notification, int $times = 1): void
    {
        $this->assertSentToTimes(new AnonymousNotifiable(), $notification, $times);
    }

    /**
     * Assert if a notification was sent a number of times.
     */
    public function assertSentToTimes(mixed $notifiable, string $notification, int $times = 1): void
    {
        $count = $this->sent($notifiable, $notification)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "Expected [{$notification}] to be sent {$times} times, but was sent {$count} times."
        );
    }

    /**
     * Determine if a notification was sent based on a truth-test callback.
     *
     * @throws Exception
     */
    public function assertNotSentTo(mixed $notifiable, Closure|string $notification, ?callable $callback = null): void
    {
        if (is_array($notifiable) || $notifiable instanceof Collection) {
            if (count($notifiable) === 0) {
                throw new Exception('No notifiable given.');
            }

            foreach ($notifiable as $singleNotifiable) {
                $this->assertNotSentTo($singleNotifiable, $notification, $callback);
            }

            return;
        }

        if ($notification instanceof Closure) {
            [$notification, $callback] = [$this->firstClosureParameterType($notification), $notification];
        }

        PHPUnit::assertCount(
            0,
            $this->sent($notifiable, $notification, $callback),
            "The unexpected [{$notification}] notification was sent."
        );
    }

    /**
     * Assert that no notifications were sent.
     */
    public function assertNothingSent(): void
    {
        $notificationNames = Collection::make($this->notifications)
            ->map(
                fn ($notifiableModels) => Collection::make($notifiableModels)
                    ->map(fn ($notifiables) => Collection::make($notifiables)->keys())
            )
            ->flatten()->join("\n- ");

        PHPUnit::assertEmpty($this->notifications, "The following notifications were sent unexpectedly:\n\n- {$notificationNames}\n");
    }

    /**
     * Assert that no notifications were sent to the given notifiable.
     *
     * @throws Exception
     */
    public function assertNothingSentTo(mixed $notifiable): void
    {
        if (is_array($notifiable) || $notifiable instanceof Collection) {
            if (count($notifiable) === 0) {
                throw new Exception('No notifiable given.');
            }

            foreach ($notifiable as $singleNotifiable) {
                $this->assertNothingSentTo($singleNotifiable);
            }

            return;
        }

        PHPUnit::assertEmpty(
            $this->notifications[get_class($notifiable)][$notifiable->getKey()] ?? [],
            'Notifications were sent unexpectedly.',
        );
    }

    /**
     * Assert the total amount of times a notification was sent.
     */
    public function assertSentTimes(string $notification, int $expectedCount): void
    {
        $actualCount = Collection::make($this->notifications)
            ->flatten(1)
            ->reduce(fn ($count, $sent) => $count + count($sent[$notification] ?? []), 0);

        PHPUnit::assertSame(
            $expectedCount,
            $actualCount,
            "Expected [{$notification}] to be sent {$expectedCount} times, but was sent {$actualCount} times."
        );
    }

    /**
     * Assert the total count of notification that were sent.
     */
    public function assertCount(int $expectedCount): void
    {
        $actualCount = Collection::make($this->notifications)->flatten(3)->count();

        PHPUnit::assertSame(
            $expectedCount,
            $actualCount,
            "Expected {$expectedCount} notifications to be sent, but {$actualCount} were sent."
        );
    }

    /**
     * Get all of the notifications matching a truth-test callback.
     */
    public function sent(mixed $notifiable, string $notification, ?callable $callback = null): Collection
    {
        if (! $this->hasSent($notifiable, $notification)) {
            return Collection::make();
        }

        $callback = $callback ?: fn () => true;

        $notifications = Collection::make($this->notificationsFor($notifiable, $notification));

        return $notifications->filter(
            fn ($arguments) => $callback(...array_values($arguments))
        )->pluck('notification');
    }

    /**
     * Determine if there are more notifications left to inspect.
     */
    public function hasSent(mixed $notifiable, string $notification): bool
    {
        return ! empty($this->notificationsFor($notifiable, $notification));
    }

    /**
     * Get all of the notifications for a notifiable entity by type.
     */
    protected function notificationsFor(mixed $notifiable, string $notification): array
    {
        return $this->notifications[get_class($notifiable)][$notifiable->getKey()][$notification] ?? [];
    }

    /**
     * Send the given notification to the given notifiable entities.
     */
    public function send(mixed $notifiables, mixed $notification): void
    {
        $this->sendNow($notifiables, $notification);
    }

    /**
     * Send the given notification immediately.
     */
    public function sendNow(mixed $notifiables, mixed $notification, ?array $channels = null): void
    {
        if (! $notifiables instanceof Collection && ! is_array($notifiables)) {
            $notifiables = [$notifiables];
        }

        foreach ($notifiables as $notifiable) {
            if (! $notification->id) {
                $notification->id = Str::uuid()->toString();
            }

            $notifiableChannels = $channels ?: $notification->via($notifiable);

            if (method_exists($notification, 'shouldSend')) {
                $notifiableChannels = array_filter(
                    $notifiableChannels,
                    fn ($channel) => $notification->shouldSend($notifiable, $channel) !== false
                );
            }

            if (empty($notifiableChannels)) {
                continue;
            }

            // TODO: support queable in the future
            $this->notifications[get_class($notifiable)][$notifiable->getKey()][get_class($notification)][] = [
                'notification' => $notification,
                'channels' => $notifiableChannels,
                'notifiable' => $notifiable,
                'locale' => $notification->locale ?? $this->locale ?? value(function () use ($notifiable) {
                    if ($notifiable instanceof HasLocalePreference) {
                        return $notifiable->preferredLocale();
                    }
                }),
            ];
        }
    }

    /**
     * Get a channel instance by name.
     */
    public function channel(?string $name = null): mixed
    {
        return null;
    }

    /**
     * Set the locale of notifications.
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Get the notifications that have been sent.
     */
    public function sentNotifications(): array
    {
        return $this->notifications;
    }
}
