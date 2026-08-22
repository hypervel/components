<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Mail\Factory;
use Hypervel\Contracts\Mail\Mailable;
use Hypervel\Contracts\Mail\Mailer;
use Hypervel\Contracts\Mail\MailQueue;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Mail\Mailables\Address;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\PendingMail;
use Hypervel\Mail\SentMessage;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\ReflectsClosures;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use UnitEnum;

use function Hypervel\Support\enum_value;

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
        public MailManager $manager
    ) {
    }

    /**
     * Assert if a mailable was sent based on a truth-test callback.
     */
    public function assertSent(Closure|string $mailable, array|callable|int|string|null $callback = null): void
    {
        [$mailable, $callback] = $this->prepareMailableAndCallback($mailable, $callback);

        if (is_int($callback)) {
            $this->assertSentTimes($mailable, $callback);
            return;
        }

        $suggestion = count($this->queuedMailables) ? ' Did you mean to use assertQueued() instead?' : '';

        if (is_array($callback) || is_string($callback)) {
            foreach (Arr::wrap($callback) as $address) {
                $callback = fn ($mail) => $mail->hasTo($address);

                PHPUnit::assertTrue(
                    $this->sent($mailable, $callback)->count() > 0,
                    "The expected [{$mailable}] mailable was not sent to address [{$address}]." . $suggestion
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
    public function assertSentTimes(string $mailable, int $times = 1): void
    {
        $count = $this->sent($mailable)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            sprintf(
                "The expected [{$mailable}] mailable was sent {$count} %s instead of {$times} %s.",
                Str::plural('time', $count),
                Str::plural('time', $times)
            )
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
        $mailableNames = (new Collection($this->mailables))->map(
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

        if (is_int($callback)) {
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
    public function assertQueuedTimes(string $mailable, int $times = 1): void
    {
        $count = $this->queued($mailable)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            sprintf(
                "The expected [{$mailable}] mailable was queued {$count} %s instead of {$times} %s.",
                Str::plural('time', $count),
                Str::plural('time', $times)
            )
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
        $total = (new Collection($this->mailables))->count();

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
        $total = (new Collection($this->mailables))
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
            return new Collection;
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
            return new Collection;
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
        return (new Collection($this->mailables))->filter(fn ($mailable) => $mailable instanceof $type);
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
    public function mailer(UnitEnum|string|null $name = null): Mailer
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $this->currentMailer = $name === '' ? null : $name;

        return $this;
    }

    /**
     * Get a mailer driver instance.
     */
    public function driver(UnitEnum|string|null $driver = null): Mailer
    {
        return $this->mailer($driver);
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function to(mixed $users, ?string $name = null): PendingMail
    {
        if (! is_null($name) && is_string($users)) {
            $users = new Address($users, $name);
        }

        return (new PendingMailFake($this))->to($users);
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function cc(mixed $users, ?string $name = null): PendingMail
    {
        if (! is_null($name) && is_string($users)) {
            $users = new Address($users, $name);
        }

        return (new PendingMailFake($this))->cc($users);
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function bcc(mixed $users, ?string $name = null): PendingMail
    {
        if (! is_null($name) && is_string($users)) {
            $users = new Address($users, $name);
        }

        return (new PendingMailFake($this))->bcc($users);
    }

    /**
     * Send a new message with only an HTML part.
     */
    public function html(string $html, Closure|string $callback): ?SentMessage
    {
        $this->pullCurrentMailer();

        return null;
    }

    /**
     * Send a new message with only a raw text part.
     */
    public function raw(string $text, Closure|string $callback): ?SentMessage
    {
        $this->pullCurrentMailer();

        return null;
    }

    /**
     * Send a new message with only a plain part.
     */
    public function plain(string $view, array $data, Closure|string $callback): ?SentMessage
    {
        $this->pullCurrentMailer();

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
        if ($shouldQueue) {
            return $this->queue($view);
        }

        $mailer = $this->pullCurrentMailer();

        if (! $view instanceof Mailable) {
            return null;
        }

        $view->mailer($mailer);

        $this->mailables[] = $view;

        return null;
    }

    /**
     * Queue a new message for sending.
     *
     * @throws InvalidArgumentException
     */
    public function queue(array|Mailable|string $view, UnitEnum|string|null $queue = null): mixed
    {
        $mailer = $this->pullCurrentMailer();

        if (! $view instanceof Mailable) {
            throw new InvalidArgumentException('Only mailables may be queued.');
        }

        $view->mailer($mailer);

        if ($queue !== null) {
            // Queueable owns identifier normalization, so it is intentionally absent from the Mailable contract.
            $view->onQueue($queue); // @phpstan-ignore method.notFound
        }

        $this->queuedMailables[] = $view;

        return null;
    }

    /**
     * Queue a new e-mail message for sending after (n) seconds.
     *
     * @throws InvalidArgumentException
     */
    public function later(DateInterval|DateTimeInterface|int $delay, array|Mailable|string $view, UnitEnum|string|null $queue = null): mixed
    {
        return $this->queue($view, $queue);
    }

    /**
     * Queue a new mail message for sending on the given queue.
     */
    public function onQueue(UnitEnum|string|null $queue, Mailable $view): mixed
    {
        return $this->queue($view, $queue);
    }

    /**
     * Queue a new mail message for sending on the given queue.
     */
    public function queueOn(UnitEnum|string $queue, Mailable $view): mixed
    {
        return $this->onQueue($queue, $view);
    }

    /**
     * Queue a new mail message for sending after (n) seconds on the given queue.
     */
    public function laterOn(UnitEnum|string $queue, DateInterval|DateTimeInterface|int $delay, Mailable $view): mixed
    {
        return $this->later($delay, $view, $queue);
    }

    /**
     * Infer mailable class using reflection if a typehinted closure is passed to assertion.
     */
    protected function prepareMailableAndCallback(Closure|string $mailable, array|callable|int|string|null $callback): array
    {
        if ($mailable instanceof Closure) {
            return [$this->firstClosureParameterType($mailable), $mailable];
        }

        return [$mailable, $callback];
    }

    /**
     * Get and clear the mailer for the current operation.
     */
    protected function pullCurrentMailer(): string
    {
        $mailer = $this->currentMailer ?? $this->manager->getDefaultDriver();
        $this->currentMailer = null;

        return $mailer;
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
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->manager, $method, $parameters);
    }
}
