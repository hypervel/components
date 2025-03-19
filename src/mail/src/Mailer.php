<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hyperf\Macroable\Macroable;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use Hypervel\Mail\Contracts\Mailable;
use Hypervel\Mail\Contracts\Mailable as MailableContract;
use Hypervel\Mail\Contracts\Mailer as MailerContract;
use Hypervel\Mail\Contracts\MailQueue as MailQueueContract;
use Hypervel\Mail\Events\MessageSending;
use Hypervel\Mail\Events\MessageSent;
use Hypervel\Mail\Mailables\Address;
use Hypervel\Queue\Contracts\Factory as QueueFactory;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\Support\HtmlString;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

use function Hyperf\Support\value;
use function Hyperf\Tappable\tap;

class Mailer implements MailerContract, MailQueueContract
{
    use Macroable;

    /**
     * The global from address and name.
     */
    protected array $from = [];

    /**
     * The global reply-to address and name.
     */
    protected array $replyTo = [];

    /**
     * The global return path address.
     */
    protected array $returnPath = [];

    /**
     * The global to address and name.
     */
    protected array $to = [];

    /**
     * The queue factory implementation.
     */
    protected ?QueueFactory $queue = null;

    /**
     * Create a new Mailer instance.
     *
     * @param string $name the name that is configured for the mailer
     * @param FactoryInterface $views the view factory instance
     * @param TransportInterface $transport the Symfony Transport instance
     * @param null|EventDispatcherInterface $events the event dispatcher instance
     */
    public function __construct(
        protected string $name,
        protected FactoryInterface $views,
        protected TransportInterface $transport,
        protected ?EventDispatcherInterface $events = null
    ) {
    }

    /**
     * Set the global from address and name.
     */
    public function alwaysFrom(string $address, ?string $name = null): void
    {
        $this->from = compact('address', 'name');
    }

    /**
     * Set the global reply-to address and name.
     */
    public function alwaysReplyTo(string $address, ?string $name = null): void
    {
        $this->replyTo = compact('address', 'name');
    }

    /**
     * Set the global return path address.
     */
    public function alwaysReturnPath(string $address): void
    {
        $this->returnPath = compact('address');
    }

    /**
     * Set the global to address and name.
     */
    public function alwaysTo(string $address, ?string $name = null): void
    {
        $this->to = compact('address', 'name');
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function to(mixed $users, ?string $name = null): PendingMail
    {
        if (! is_null($name) && is_string($users)) {
            $users = new Address($users, $name);
        }

        return (new PendingMail($this))->to($users);
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function cc(mixed $users, ?string $name = null): PendingMail
    {
        if (! is_null($name) && is_string($users)) {
            $users = new Address($users, $name);
        }

        return (new PendingMail($this))->cc($users);
    }

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function bcc(mixed $users, ?string $name = null): PendingMail
    {
        if (! is_null($name) && is_string($users)) {
            $users = new Address($users, $name);
        }

        return (new PendingMail($this))->bcc($users);
    }

    /**
     * Send a new message with only an HTML part.
     */
    public function html(string $html, mixed $callback): ?SentMessage
    {
        return $this->send(['html' => new HtmlString($html)], [], $callback);
    }

    /**
     * Send a new message with only a raw text part.
     */
    public function raw(string $text, mixed $callback): ?SentMessage
    {
        return $this->send(['raw' => $text], [], $callback);
    }

    /**
     * Send a new message with only a plain part.
     */
    public function plain(string $view, array $data, mixed $callback): ?SentMessage
    {
        return $this->send(['text' => $view], $data, $callback);
    }

    /**
     * Render the given message as a view.
     */
    public function render(array|string $view, array $data = []): string
    {
        // First we need to parse the view, which could either be a string or an array
        // containing both an HTML and plain text versions of the view which should
        // be used when sending an e-mail. We will extract both of them out here.
        [$view, $plain, $raw] = $this->parseView($view);

        $data['message'] = $this->createMessage();

        return $this->replaceEmbeddedAttachments(
            $this->renderView($view ?: $plain, $data),
            $data['message']->getSymfonyMessage()->getAttachments()
        );
    }

    /**
     * Replace the embedded image attachments with raw, inline image data for browser rendering.
     */
    protected function replaceEmbeddedAttachments(string $renderedView, array $attachments): string
    {
        if (preg_match_all('/<img.+?src=[\'"]cid:([^\'"]+)[\'"].*?>/i', $renderedView, $matches)) {
            foreach (array_unique($matches[1]) as $image) {
                foreach ($attachments as $attachment) {
                    if ($attachment->getFilename() === $image) {
                        $renderedView = str_replace(
                            'cid:' . $image,
                            'data:' . $attachment->getContentType() . ';base64,' . $attachment->bodyToString(),
                            $renderedView
                        );

                        break;
                    }
                }
            }
        }

        return $renderedView;
    }

    /**
     * Send a new message using a view.
     */
    public function send(array|Mailable|string $view, array $data = [], null|Closure|string $callback = null): ?SentMessage
    {
        if ($view instanceof MailableContract) {
            return $this->sendMailable($view);
        }

        $data['mailer'] = $this->name;

        // First we need to parse the view, which could either be a string or an array
        // containing both an HTML and plain text versions of the view which should
        // be used when sending an e-mail. We will extract both of them out here.
        [$view, $plain, $raw] = $this->parseView($view);

        $data['message'] = $message = $this->createMessage();

        // Once we have retrieved the view content for the e-mail we will set the body
        // of this message using the HTML type, which will provide a simple wrapper
        // to creating view based emails that are able to receive arrays of data.
        if (! is_null($callback)) {
            $callback($message);
        }

        $this->addContent($message, $view, $plain, $raw, $data);

        // If a global "to" address has been set, we will set that address on the mail
        // message. This is primarily useful during local development in which each
        // message should be delivered into a single mail address for inspection.
        if (isset($this->to['address'])) {
            $this->setGlobalToAndRemoveCcAndBcc($message);
        }

        // Next we will determine if the message should be sent. We give the developer
        // one final chance to stop this message and then we will send it to all of
        // its recipients. We will then fire the sent event for the sent message.
        $symfonyMessage = $message->getSymfonyMessage();

        if ($this->shouldSendMessage($symfonyMessage, $data)) {
            $symfonySentMessage = $this->sendSymfonyMessage($symfonyMessage);

            if ($symfonySentMessage) {
                $sentMessage = new SentMessage($symfonySentMessage);

                $this->dispatchSentEvent($sentMessage, $data);

                return $sentMessage;
            }
        }

        return null;
    }

    /**
     * Send the given mailable.
     */
    protected function sendMailable(MailableContract $mailable): ?SentMessage
    {
        return $mailable instanceof ShouldQueue
            ? $mailable->mailer($this->name)->queue($this->queue)
            : $mailable->mailer($this->name)->send($this);
    }

    /**
     * Send a new message synchronously using a view.
     */
    public function sendNow(array|MailableContract|string $mailable, array $data = [], null|Closure|string $callback = null): ?SentMessage
    {
        return $mailable instanceof MailableContract
            ? $mailable->mailer($this->name)->send($this)
            : $this->send($mailable, $data, $callback);
    }

    /**
     * Parse the given view name or array.
     *
     * @throws InvalidArgumentException
     */
    protected function parseView(array|Closure|string $view): array
    {
        if (is_string($view) || $view instanceof Closure) {
            return [$view, null, null];
        }

        // If the given view is an array with numeric keys, we will just assume that
        // both a "pretty" and "plain" view were provided, so we will return this
        // array as is, since it should contain both views with numerical keys.
        if (is_array($view) && isset($view[0])) {
            return [$view[0], $view[1], null];
        }

        // If this view is an array but doesn't contain numeric keys, we will assume
        // the views are being explicitly specified and will extract them via the
        // named keys instead, allowing the developers to use one or the other.
        if (is_array($view)) {
            return [
                $view['html'] ?? null,
                $view['text'] ?? null,
                $view['raw'] ?? null,
            ];
        }

        throw new InvalidArgumentException('Invalid view.');
    }

    /**
     * Add the content to a given message.
     */
    protected function addContent(Message $message, null|Closure|Htmlable|string $view, null|Closure|Htmlable|string $plain, ?string $raw, array $data = []): void
    {
        if (isset($view)) {
            $message->html($this->renderView($view, $data) ?: ' ');
        }

        if (isset($plain)) {
            $message->text($this->renderView($plain, $data) ?: ' ');
        }

        if (isset($raw)) {
            $message->text($raw);
        }
    }

    /**
     * Render the given view.
     */
    protected function renderView(Closure|Htmlable|string $view, array $data): string
    {
        $view = value($view, $data);

        return $view instanceof Htmlable
            ? $view->toHtml()
            : $this->views->make($view, $data)->render();
    }

    /**
     * Set the global "to" address on the given message.
     */
    protected function setGlobalToAndRemoveCcAndBcc(Message $message): void
    {
        $message->forgetTo();

        $message->to($this->to['address'], $this->to['name'], true);

        $message->forgetCc();
        $message->forgetBcc();
    }

    /**
     * Queue a new mail message for sending.
     *
     * @throws InvalidArgumentException
     */
    public function queue(array|MailableContract|string $view, ?string $queue = null): mixed
    {
        if (! $view instanceof MailableContract) {
            throw new InvalidArgumentException('Only mailables may be queued.');
        }

        if (is_string($queue)) {
            $view->onQueue($queue); // @phpstan-ignore-line
        }

        return $view->mailer($this->name)->queue($this->queue);
    }

    /**
     * Queue a new mail message for sending on the given queue.
     */
    public function onQueue(?string $queue, MailableContract $view): mixed
    {
        return $this->queue($view, $queue);
    }

    /**
     * Queue a new mail message for sending on the given queue.
     *
     * This method didn't match rest of framework's "onQueue" phrasing. Added "onQueue".
     */
    public function queueOn(string $queue, MailableContract $view): mixed
    {
        return $this->onQueue($queue, $view);
    }

    /**
     * Queue a new mail message for sending after (n) seconds.
     *
     * @throws InvalidArgumentException
     */
    public function later(DateInterval|DateTimeInterface|int $delay, array|MailableContract|string $view, ?string $queue = null): mixed
    {
        if (! $view instanceof MailableContract) {
            throw new InvalidArgumentException('Only mailables may be queued.');
        }

        return $view->mailer($this->name)->later(
            $delay,
            is_null($queue) ? $this->queue : $queue
        );
    }

    /**
     * Queue a new mail message for sending after (n) seconds on the given queue.
     */
    public function laterOn(string $queue, DateInterval|DateTimeInterface|int $delay, MailableContract $view): mixed
    {
        return $this->later($delay, $view, $queue);
    }

    /**
     * Create a new message instance.
     */
    protected function createMessage(): Message
    {
        $message = new Message(new Email());

        // If a global from address has been specified we will set it on every message
        // instance so the developer does not have to repeat themselves every time
        // they create a new message. We'll just go ahead and push this address.
        if (! empty($this->from['address'])) {
            $message->from($this->from['address'], $this->from['name']);
        }

        // When a global reply address was specified we will set this on every message
        // instance so the developer does not have to repeat themselves every time
        // they create a new message. We will just go ahead and push this address.
        if (! empty($this->replyTo['address'])) {
            $message->replyTo($this->replyTo['address'], $this->replyTo['name']);
        }

        if (! empty($this->returnPath['address'])) {
            $message->returnPath($this->returnPath['address']);
        }

        return $message;
    }

    /**
     * Send a Symfony Email instance.
     */
    protected function sendSymfonyMessage(Email $message): ?SymfonySentMessage
    {
        try {
            return $this->transport->send($message, Envelope::create($message));
        } finally {
        }
    }

    /**
     * Determines if the email can be sent.
     */
    protected function shouldSendMessage(Email $message, array $data = []): bool
    {
        if (! $this->events) {
            return true;
        }

        return tap(new MessageSending($message, $data), function ($event) {
            $this->events->dispatch($event);
        })->shouldSend();
    }

    /**
     * Dispatch the message sent event.
     */
    protected function dispatchSentEvent(SentMessage $message, array $data = []): void
    {
        $this->events?->dispatch(
            new MessageSent($message, $data)
        );
    }

    /**
     * Get the Symfony Transport instance.
     */
    public function getSymfonyTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Get the view factory instance.
     */
    public function getViewFactory(): FactoryInterface
    {
        return $this->views;
    }

    /**
     * Set the Symfony Transport instance.
     */
    public function setSymfonyTransport(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Set the queue manager instance.
     */
    public function setQueue(QueueFactory $queue): static
    {
        $this->queue = $queue;

        return $this;
    }
}
