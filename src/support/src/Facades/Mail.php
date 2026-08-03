<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Support\Testing\Fakes\MailFake;

/**
 * @method static \Hypervel\Contracts\Mail\Mailer mailer(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Contracts\Mail\Mailer driver(\UnitEnum|string|null $driver = null)
 * @method static \Hypervel\Mail\Mailer build(array $config)
 * @method static \Symfony\Component\Mailer\Transport\TransportInterface createSymfonyTransport(array $config)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(\UnitEnum|string $name)
 * @method static void purge(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Mail\MailManager extend(string $driver, \Closure $callback, bool $poolable = false)
 * @method static \Hypervel\Contracts\Container\Container getApplication()
 * @method static \Hypervel\Mail\MailManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static \Hypervel\Mail\MailManager forgetMailers()
 * @method static \Hypervel\Mail\MailManager setReleaseCallback(string $driver, \Closure $callback)
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static \Hypervel\Mail\MailManager addPoolable(string $driver)
 * @method static \Hypervel\Mail\MailManager removePoolable(string $driver)
 * @method static array getPoolables()
 * @method static \Hypervel\Mail\MailManager setPoolables(array $poolables)
 * @method static void alwaysFrom(string $address, string|null $name = null)
 * @method static void alwaysReplyTo(string $address, string|null $name = null)
 * @method static void alwaysReturnPath(string $address)
 * @method static void alwaysTo(string $address, string|null $name = null)
 * @method static \Hypervel\Mail\PendingMail to(mixed $users, string|null $name = null)
 * @method static \Hypervel\Mail\PendingMail cc(mixed $users, string|null $name = null)
 * @method static \Hypervel\Mail\PendingMail bcc(mixed $users, string|null $name = null)
 * @method static \Hypervel\Mail\SentMessage|null html(string $html, \Closure|string $callback)
 * @method static \Hypervel\Mail\SentMessage|null raw(string $text, \Closure|string $callback)
 * @method static \Hypervel\Mail\SentMessage|null plain(string $view, array $data, \Closure|string $callback)
 * @method static string render(\Closure|array|string $view, array $data = [])
 * @method static \Hypervel\Mail\SentMessage|null send(\Hypervel\Contracts\Mail\Mailable|array|string $view, array $data = [], \Closure|string|null $callback = null)
 * @method static \Hypervel\Mail\SentMessage|null sendNow(\Hypervel\Contracts\Mail\Mailable|array|string $mailable, array $data = [], \Closure|string|null $callback = null)
 * @method static mixed queue(\Hypervel\Contracts\Mail\Mailable|array|string $view, \UnitEnum|string|null $queue = null)
 * @method static mixed onQueue(\UnitEnum|string|null $queue, \Hypervel\Contracts\Mail\Mailable $view)
 * @method static mixed queueOn(\UnitEnum|string $queue, \Hypervel\Contracts\Mail\Mailable $view)
 * @method static mixed later(\DateInterval|\DateTimeInterface|int $delay, \Hypervel\Contracts\Mail\Mailable|array|string $view, \UnitEnum|string|null $queue = null)
 * @method static mixed laterOn(\UnitEnum|string $queue, \DateInterval|\DateTimeInterface|int $delay, \Hypervel\Contracts\Mail\Mailable $view)
 * @method static \Symfony\Component\Mailer\Transport\TransportInterface getSymfonyTransport()
 * @method static \Hypervel\Contracts\View\Factory getViewFactory()
 * @method static void setSymfonyTransport(\Symfony\Component\Mailer\Transport\TransportInterface $transport)
 * @method static \Hypervel\Mail\Mailer setQueue(\Hypervel\Contracts\Queue\Factory $queue)
 * @method static void flushState()
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static void assertSent(\Closure|string $mailable, callable|array|string|int|null $callback = null)
 * @method static void assertSentTimes(string $mailable, int $times = 1)
 * @method static void assertNotOutgoing(\Closure|string $mailable, callable|null $callback = null)
 * @method static void assertNotSent(\Closure|string $mailable, callable|array|string|null $callback = null)
 * @method static void assertNothingOutgoing()
 * @method static void assertNothingSent()
 * @method static void assertQueued(\Closure|string $mailable, callable|array|string|int|null $callback = null)
 * @method static void assertQueuedTimes(string $mailable, int $times = 1)
 * @method static void assertNotQueued(\Closure|string $mailable, callable|array|string|null $callback = null)
 * @method static void assertNothingQueued()
 * @method static void assertSentCount(int $count)
 * @method static void assertQueuedCount(int $count)
 * @method static void assertOutgoingCount(int $count)
 * @method static \Hypervel\Support\Collection sent(\Closure|string $mailable, callable|null $callback = null)
 * @method static bool hasSent(string $mailable)
 * @method static \Hypervel\Support\Collection queued(\Closure|string $mailable, callable|null $callback = null)
 * @method static bool hasQueued(string $mailable)
 *
 * @see \Hypervel\Mail\MailManager
 * @see \Hypervel\Support\Testing\Fakes\MailFake
 */
class Mail extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(): MailFake
    {
        $actualMailManager = static::isFake()
            ? static::getFacadeRoot()->manager
            : static::getFacadeRoot();

        return tap(new MailFake($actualMailManager), function ($fake) {
            static::swap($fake);
        });
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'mail.manager';
    }
}
