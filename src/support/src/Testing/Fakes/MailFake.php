<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hyperf\Support\Traits\ForwardsCalls;
use Hypervel\Mail\Contracts\Factory;
use Hypervel\Mail\Contracts\Mailable;
use Hypervel\Mail\Contracts\Mailer;
use Hypervel\Mail\Contracts\MailQueue;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\PendingMail;
use Hypervel\Mail\SentMessage;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Support\Traits\ReflectsClosures;
use PHPUnit\Framework\Assert as PHPUnit;

class MailFake implements Factory, Fake, Mailer, MailQueue
{
    use ForwardsCalls;
    use ReflectsClosures;

    /**
     * The mailer currently being used to send a message.
     */
    protected ?string $currentMailer = null;

    /**
     * All of the mailables that have been sent.
     */
    protected array $mailables = [];

    /**
     * All of the mailables that have been queued.
     */
    protected array $queuedMailables = [];

    /**
     * Create a new mail fake.
     */
    public function __construct(
        protected MailManager $manager
    ) {
    }

    /**
     * Assert if a mailable was sent based on a truth-test callback.
     */
    public function assertSent(Closure|string $mailable, array|callable|int|string|null $callback = null): void
    {
        [$mailable, $callback] = $this->prepareMailableAndCallback($mailable, $callback);

        if (is_numeric($callback)) {
            $this->assertSentTimes($mailable, $callback);
            return;
        }

        $suggestion = count($this->queuedMailables) ? ' Did you mean to use assertQueued() instead?' : '';

        if (is_array($callback) || is_string($callback)) {
            foreach (Arr::wrap($callback) as $address) {
                $callback = fn ($mail) => $mail->hasTo($address);

                PHPUnit::assertTrue(
                    $this->sent($mailable, $callback)->count() > 0,
                    "The expected [{$mailable}] mailable was not sent to address [{$address}]."
                );
            }

            return;
        }

        PHPUnit::assertTrue(
            $this->sent($mailable, $callback)->count() > 0,
            "The expected [{$mailable}] mailable was not sent." . $suggestion
        );
    }

    /**
     * Assert if a mailable was sent a number of times.
     */
    protected function assertSentTimes(string $mailable, int $times = 1): void
    {
        $count = $this->sent($mailable)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$mailable}] mailable was sent {$count} times instead of {$times} times."
        );
    }

    /**
     * Determine if a mailable was not sent or queued to be sent based on a truth-test callback.
     */
    public function assertNotOutgoing(Closure|string $mailable, ?callable $callback = null): void
    {
        $this->assertNotSent($mailable, $callback);
        $this->assertNotQueued($mailable, $callback);
    }

    /**
     * Determine if a mailable was not sent based on a truth-test callback.
     */
    public function assertNotSent(Closure|string $mailable, array|callable|string|null $callback = null): void
    {
        if (is_string($callback) || is_array($callback)) {
            foreach (Arr::wrap($callback) as $address) {
                $callback = fn ($mail) => $mail->hasTo($address);

                PHPUnit::assertCount(
                    0,
                    $this->sent($mailable, $callback),
                    "The unexpected [{$mailable}] mailable was sent to address [{$address}]."
                );
            }

            return;
        }

        [$mailable, $callback] = $this->prepareMailableAndCallback($mailable, $callback);

        PHPUnit::assertCount(
            0,
            $this->sent($mailable, $callback),
            "The unexpected [{$mailable}] mailable was sent."
        );
    }

    /**
     * Assert that no mailables were sent or queued to be sent.
     */
    public function assertNothingOutgoing(): void
    {
        $this->assertNothingSent();
        $this->assertNothingQueued();
    }

    /**
     * Assert that no mailables were sent.
     */
    public function assertNothingSent(): void
    {
        $mailableNames = Collection::make($this->mailables)->map(
            fn ($mailable) => get_class($mailable)
        )->join("\n- ");

        PHPUnit::assertEmpty($this->mailables, "The following mailables were sent unexpectedly:\n\n- {$mailableNames}\n");
    }

    /**
     * Assert if a mailable was queued based on a truth-test callback.
     */
    public function assertQueued(Closure|string $mailable, array|callable|int|string|null $callback = null): void
    {
        [$mailable, $callback] = $this->prepareMailableAndCallback($mailable, $callback);

        if (is_numeric($callback)) {
            $this->assertQueuedTimes($mailable, $callback);
            return;
        }

        if (is_string($callback) || is_array($callback)) {
            foreach (Arr::wrap($callback) as $address) {
                $callback = fn ($mail) => $mail->hasTo($address);

                PHPUnit::assertTrue(
                    $this->queued($mailable, $callback)->count() > 0,
                    "The expected [{$mailable}] mailable was not queued to address [{$address}]."
                );
            }

            return;
        }

        PHPUnit::assertTrue(
            $this->queued($mailable, $callback)->count() > 0,
            "The expected [{$mailable}] mailable was not queued."
        );
    }

    /**
     * Assert if a mailable was queued a number of times.
     */
    protected function assertQueuedTimes(string $mailable, int $times = 1): void
    {
        $count = $this->queued($mailable)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$mailable}] mailable was queued {$count} times instead of {$times} times."
        );
    }

    /**
     * Determine if a mailable was not queued based on a truth-test callback.
     */
    public function assertNotQueued(Closure|string $mailable, array|callable|string|null $callback = null): void
    {
        if (is_string($callback) || is_array($callback)) {
            foreach (Arr::wrap($callback) as $address) {
                $callback = fn ($mail) => $mail->hasTo($address);

                PHPUnit::assertCount(
                    0,
                    $this->queued($mailable, $callback),
                    "The unexpected [{$mailable}] mailable was queued to address [{$address}]."
                );
            }

            return;
        }

        [$mailable, $callback] = $this->prepareMailableAndCallback($mailable, $callback);

        PHPUnit::assertCount(
            0,
            $this->queued($mailable, $callback),
            "The unexpected [{$mailable}] mailable was queued."
        );
    }

    /**
     * Assert that no mailables were queued.
     */
    public function assertNothingQueued(): void
    {
        $mailableNames = (new Collection($this->queuedMailables))->map(
            fn ($mailable) => get_class($mailable)
        )->join("\n- ");

        PHPUnit::assertEmpty($this->queuedMailables, "The following mailables were queued unexpectedly:\n\n- {$mailableNames}\n");
    }

    /**
     * Assert the total number of mailables that were sent.
     */
    public function assertSentCount(int $count): void
    {
        $total = Collection::make($this->mailables)->count();

        PHPUnit::assertSame(
            $count,
            $total,
            "The total number of mailables sent was {$total} instead of {$count}."
        );
    }

    /**
     * Assert the total number of mailables that were queued.
     */
    public function assertQueuedCount(int $count): void
    {
        $total = (new Collection($this->queuedMailables))->count();

        PHPUnit::assertSame(
            $count,
            $total,
            "The total number of mailables queued was {$total} instead of {$count}."
        );
    }

    /**
     * Assert the total number of mailables that were sent or queued.
     */
    public function assertOutgoingCount(int $count): void
    {
        $total = Collection::make($this->mailables)
            ->concat($this->queuedMailables)
            ->count();

        PHPUnit::assertSame(
            $count,
            $total,
            "The total number of outgoing mailables was {$total} instead of {$count}."
        );
    }

    /**
     * Get all of the mailables matching a truth-test callback.
     */
    public function sent(Closure|string $mailable, ?callable $callback = null): Collection
    {
        [$mailable, $callback] = $this->prepareMailableAndCallback($mailable, $callback);

        if (! $this->hasSent($mailable)) {
            return Collection::make();
        }

        $callback = $callback ?: fn () => true;

        return $this->mailablesOf($mailable)->filter(fn ($mailable) => $callback($mailable));
    }

    /**
     * Determine if the given mailable has been sent.
     */
    public function hasSent(string $mailable): bool
    {
        return $this->mailablesOf($mailable)->count() > 0;
    }

    /**
     * Get all of the queued mailables matching a truth-test callback.
     */
    public function queued(Closure|string $mailable, ?callable $callback = null): Collection
    {
        [$mailable, $callback] = $this->prepareMailableAndCallback($mailable, $callback);

        if (! $this->hasQueued($mailable)) {
            return new Collection();
        }

        $callback = $callback ?: fn () => true;

        return $this->queuedMailablesOf($mailable)->filter(fn ($mailable) => $callback($mailable));
    }

    /**
     * Determine if the given mailable has been queued.
     */
    public function hasQueued(string $mailable): bool
    {
        return $this->queuedMailablesOf($mailable)->count() > 0;
    }

    /**
     * Get all of the mailed mailables for a given type.
     */
    protected function mailablesOf(string $type): Collection
    {
        return Collection::make($this->mailables)->filter(fn ($mailable) => $mailable instanceof $type);
    }

    /**
     * Get all of the mailed mailables for a given type.
     */
    protected function queuedMailablesOf(string $type): Collection
    {
        return (new Collection($this->queuedMailables))->filter(fn ($mailable) => $mailable instanceof $type);
    }

    /**
     * Get a mailer instance by name.
     */
    public function mailer(?string $name = null): Mailer
    {
        $this->currentMailer = $name;

        return $this;
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function to(mixed $users): PendingMail
    {
        return (new PendingMailFake($this))->to($users);
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function cc(mixed $users): PendingMail
    {
        return (new PendingMailFake($this))->cc($users);
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function bcc(mixed $users): PendingMail
    {
        return (new PendingMailFake($this))->bcc($users);
    }

    /**
     * Send a new message with only a raw text part.
     */
    public function raw(string $text, mixed $callback): ?SentMessage
    {
        return null;
    }

    /**
     * Send a new message using a view.
     */
    public function send(array|Mailable|string $view, array $data = [], Closure|string|null $callback = null): ?SentMessage
    {
        $this->sendMail($view, $view instanceof ShouldQueue);

        return null;
    }

    /**
     * Send a new message synchronously using a view.
     */
    public function sendNow(array|Mailable|string $mailable, array $data = [], Closure|string|null $callback = null): ?SentMessage
    {
        $this->sendMail($mailable, false);

        return null;
    }

    /**
     * Send a new message using a view.
     */
    protected function sendMail(array|Mailable|string $view, bool $shouldQueue = false): mixed
    {
        if (! $view instanceof Mailable) {
            return null;
        }

        $view->mailer($this->currentMailer);

        if ($shouldQueue) {
            return $this->queue($view);
        }

        $this->currentMailer = null;

        $this->mailables[] = $view;

        return null;
    }

    /**
     * Queue a new message for sending.
     */
    public function queue(array|Mailable|string $view, ?string $queue = null): mixed
    {
        if (! $view instanceof Mailable) {
            return null;
        }

        $view->mailer($this->currentMailer);

        $this->currentMailer = null;

        $this->queuedMailables[] = $view;

        return null;
    }

    /**
     * Queue a new e-mail message for sending after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, array|Mailable|string $view, ?string $queue = null): mixed
    {
        return $this->queue($view, $queue);
    }

    /**
     * Infer mailable class using reflection if a typehinted closure is passed to assertion.
     */
    protected function prepareMailableAndCallback(Closure|string $mailable, callable|string|null $callback): array
    {
        if ($mailable instanceof Closure) {
            return [$this->firstClosureParameterType($mailable), $mailable];
        }

        return [$mailable, $callback];
    }

    /**
     * Forget all of the resolved mailer instances.
     */
    public function forgetMailers(): static
    {
        $this->currentMailer = null;

        return $this;
    }

    /**
     * Handle dynamic method calls to the mailer.
     */
    public function __call(string $method, array $parameters)
    {
        return $this->forwardCallTo($this->manager, $method, $parameters);
    }
}
