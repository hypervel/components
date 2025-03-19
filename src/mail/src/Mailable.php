<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hyperf\Collection\Collection;
use Hyperf\Conditionable\Conditionable;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Macroable\Macroable;
use Hyperf\Stringable\Str;
use Hyperf\Support\Traits\ForwardsCalls;
use Hypervel\Filesystem\Contracts\Factory as FilesystemFactory;
use Hypervel\Foundation\Testing\Constraints\SeeInOrder;
use Hypervel\Mail\Contracts\Attachable;
use Hypervel\Mail\Contracts\Factory;
use Hypervel\Mail\Contracts\Factory as MailFactory;
use Hypervel\Mail\Contracts\Mailable as MailableContract;
use Hypervel\Mail\Contracts\Mailer;
use Hypervel\Queue\Contracts\Factory as QueueFactory;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\Support\Contracts\Renderable;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Traits\Localizable;
use Hypervel\Translation\Contracts\HasLocalePreference;
use PHPUnit\Framework\Assert as PHPUnit;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;

use function Hyperf\Support\call;
use function Hyperf\Support\make;

class Mailable implements MailableContract, Renderable
{
    use Conditionable, ForwardsCalls, Localizable, Macroable {
        __call as macroCall;
    }

    /**
     * The locale of the message.
     */
    public string $locale = '';

    /**
     * The person the message is from.
     */
    public array $from = [];

    /**
     * The "to" recipients of the message.
     */
    public array $to = [];

    /**
     * The "cc" recipients of the message.
     */
    public array $cc = [];

    /**
     * The "bcc" recipients of the message.
     */
    public array $bcc = [];

    /**
     * The "reply to" recipients of the message.
     */
    public array $replyTo = [];

    /**
     * The subject of the message.
     */
    public string $subject = '';

    /**
     * The Markdown template for the message (if applicable).
     */
    public string $markdown = '';

    /**
     * The HTML to use for the message.
     */
    protected string $html = '';

    /**
     * The view to use for the message.
     */
    public string $view = '';

    /**
     * The plain text view to use for the message.
     */
    public string $textView = '';

    /**
     * The view data for the message.
     */
    public array $viewData = [];

    /**
     * The attachments for the message.
     */
    public array $attachments = [];

    /**
     * The raw attachments for the message.
     */
    public array $rawAttachments = [];

    /**
     * The attachments from a storage disk.
     */
    public array $diskAttachments = [];

    /**
     * The tags for the message.
     */
    protected array $tags = [];

    /**
     * The metadata for the message.
     */
    protected array $metadata = [];

    /**
     * The callbacks for the message.
     */
    public array $callbacks = [];

    /**
     * The name of the theme that should be used when formatting the message.
     */
    public ?string $theme = null;

    /**
     * The name of the mailer that should send the message.
     */
    public string $mailer = '';

    /**
     * The rendered mailable views for testing / assertions.
     */
    protected array $assertionableRenderStrings = [];

    /**
     * The callback that should be invoked while building the view data.
     *
     * @var callable
     */
    public static $viewDataCallback;

    /**
     * Send the message using the given mailer.
     */
    public function send(Factory|Mailer $mailer): ?SentMessage
    {
        return $this->withLocale($this->locale, function () use ($mailer) {
            $this->prepareMailableForDelivery();

            $mailer = $mailer instanceof MailFactory
                ? $mailer->mailer($this->mailer)
                : $mailer;

            return $mailer->send($this->buildView(), $this->buildViewData(), function ($message) {
                $this->buildFrom($message)
                    ->buildRecipients($message)
                    ->buildSubject($message)
                    ->buildTags($message)
                    ->buildMetadata($message)
                    ->runCallbacks($message)
                    ->buildAttachments($message);
            });
        });
    }

    /**
     * Queue the message for sending.
     */
    public function queue(QueueFactory $queue): mixed
    {
        if (isset($this->delay)) {
            return $this->later($this->delay, $queue);
        }

        $connection = property_exists($this, 'connection') ? $this->connection : null;

        $queueName = property_exists($this, 'queue') ? $this->queue : null;

        return $queue->connection($connection)->pushOn(
            $queueName ?: null,
            $this->newQueuedJob()
        );
    }

    /**
     * Deliver the queued message after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, QueueFactory $queue): mixed
    {
        $connection = property_exists($this, 'connection') ? $this->connection : null;

        $queueName = property_exists($this, 'queue') ? $this->queue : null;

        return $queue->connection($connection)->laterOn(
            $queueName ?: null,
            $delay,
            $this->newQueuedJob()
        );
    }

    /**
     * Make the queued mailable job instance.
     */
    protected function newQueuedJob(): mixed
    {
        return ApplicationContext::getContainer()
            ->get(SendQueuedMailable::class)
            ->through(array_merge(
                method_exists($this, 'middleware') ? $this->middleware() : [],
                $this->middleware ?? []
            ));
    }

    /**
     * Render the mailable into a view.
     *
     * @throws ReflectionException
     */
    public function render(): string
    {
        return $this->withLocale($this->locale, function () {
            $this->prepareMailableForDelivery();

            return ApplicationContext::getContainer()
                ->get(Mailer::class)
                ->render(
                    $this->buildView(),
                    $this->buildViewData()
                );
        });
    }

    /**
     * Build the view for the message.
     *
     * @throws ReflectionException
     */
    protected function buildView(): array|string
    {
        if (isset($this->html)) {
            return array_filter([
                'html' => new HtmlString($this->html),
                'text' => $this->textView ?? null,
            ]);
        }

        if (isset($this->markdown)) {
            return $this->buildMarkdownView();
        }

        if (isset($this->view, $this->textView)) {
            return [$this->view, $this->textView];
        }
        if (isset($this->textView)) {
            return ['text' => $this->textView];
        }

        return $this->view;
    }

    /**
     * Build the Markdown view for the message.
     *
     * @throws ReflectionException
     */
    protected function buildMarkdownView(): array
    {
        $data = $this->buildViewData();

        return [
            'html' => $this->buildMarkdownHtml($data),
            'text' => $this->buildMarkdownText($data),
        ];
    }

    /**
     * Build the view data for the message.
     *
     * @throws ReflectionException
     */
    public function buildViewData(): array
    {
        $data = $this->viewData;

        if (static::$viewDataCallback) {
            $data = array_merge($data, call_user_func(static::$viewDataCallback, $this));
        }

        foreach ((new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this) && $property->getDeclaringClass()->getName() !== self::class) {
                $data[$property->getName()] = $property->getValue($this);
            }
        }

        return $data;
    }

    /**
     * Build the HTML view for a Markdown message.
     */
    protected function buildMarkdownHtml(array $viewData): Closure
    {
        return fn ($data) => $this->markdownRenderer()->render(
            $this->markdown,
            array_merge($data, $viewData),
        );
    }

    /**
     * Build the text view for a Markdown message.
     */
    protected function buildMarkdownText(array $viewData): Closure
    {
        return function ($data) use ($viewData) {
            if (isset($data['message'])) {
                $data = array_merge($data, [
                    'message' => new TextMessage($data['message']),
                ]);
            }

            return $this->textView ?? $this->markdownRenderer()->renderText(
                $this->markdown,
                array_merge($data, $viewData)
            );
        };
    }

    /**
     * Resolves a Markdown instance with the mail's theme.
     */
    protected function markdownRenderer(): Markdown
    {
        return tap(make(Markdown::class), function ($markdown) {
            $markdown->theme(
                $this->theme ?: ApplicationContext::getContainer()->get(ConfigInterface::class)->get(
                    'mail.markdown.theme',
                    'default'
                )
            );
        });
    }

    /**
     * Add the sender to the message.
     */
    protected function buildFrom(Message $message): static
    {
        if (! empty($this->from)) {
            $message->from($this->from[0]['address'], $this->from[0]['name']);
        }

        return $this;
    }

    /**
     * Add all of the recipients to the message.
     */
    protected function buildRecipients(Message $message): static
    {
        foreach (['to', 'cc', 'bcc', 'replyTo'] as $type) {
            foreach ($this->{$type} as $recipient) {
                $message->{$type}($recipient['address'], $recipient['name']);
            }
        }

        return $this;
    }

    /**
     * Set the subject for the message.
     */
    protected function buildSubject(Message $message): static
    {
        if ($this->subject) {
            $message->subject($this->subject);
        } else {
            $message->subject(Str::title(Str::snake(class_basename($this), ' ')));
        }

        return $this;
    }

    /**
     * Add all of the attachments to the message.
     */
    protected function buildAttachments(Message $message): static
    {
        foreach ($this->attachments as $attachment) {
            $message->attach($attachment['file'], $attachment['options']);
        }

        foreach ($this->rawAttachments as $attachment) {
            $message->attachData(
                $attachment['data'],
                $attachment['name'],
                $attachment['options']
            );
        }

        $this->buildDiskAttachments($message);

        return $this;
    }

    /**
     * Add all of the disk attachments to the message.
     */
    protected function buildDiskAttachments(Message $message): void
    {
        foreach ($this->diskAttachments as $attachment) {
            $storage = ApplicationContext::getContainer()->get(
                FilesystemFactory::class
            )->disk($attachment['disk']);

            $message->attachData(
                $storage->get($attachment['path']),
                $attachment['name'] ?? basename($attachment['path']),
                array_merge(['mime' => $storage->mimeType($attachment['path'])], $attachment['options'])
            );
        }
    }

    /**
     * Add all defined tags to the message.
     */
    protected function buildTags(Message $message): static
    {
        if ($this->tags) {
            foreach ($this->tags as $tag) {
                $message->getHeaders()->add(new TagHeader($tag));
            }
        }

        return $this;
    }

    /**
     * Add all defined metadata to the message.
     */
    protected function buildMetadata(Message $message): static
    {
        if ($this->metadata) {
            foreach ($this->metadata as $key => $value) {
                $message->getHeaders()->add(new MetadataHeader($key, $value));
            }
        }

        return $this;
    }

    /**
     * Run the callbacks for the message.
     */
    protected function runCallbacks(Message $message): static
    {
        foreach ($this->callbacks as $callback) {
            $callback($message->getSymfonyMessage());
        }

        return $this;
    }

    /**
     * Set the locale of the message.
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Set the priority of this message.
     *
     * The value is an integer where 1 is the highest priority and 5 is the lowest.
     *
     * @return $this
     */
    public function priority(int $level = 3): static
    {
        $this->callbacks[] = function ($message) use ($level) {
            $message->priority($level);
        };

        return $this;
    }

    /**
     * Set the sender of the message.
     */
    public function from(array|object|string $address, ?string $name = null): static
    {
        return $this->setAddress($address, $name, 'from');
    }

    /**
     * Determine if the given recipient is set on the mailable.
     */
    public function hasFrom(array|object|string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($address, $name, 'from');
    }

    /**
     * Set the recipients of the message.
     */
    public function to(array|object|string $address, ?string $name = null): static
    {
        if (! $this->locale && $address instanceof HasLocalePreference) {
            $this->locale($address->preferredLocale());
        }

        return $this->setAddress($address, $name, 'to');
    }

    /**
     * Determine if the given recipient is set on the mailable.
     */
    public function hasTo(array|object|string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($address, $name, 'to');
    }

    /**
     * Set the recipients of the message.
     */
    public function cc(array|object|string $address, ?string $name = null): static
    {
        return $this->setAddress($address, $name, 'cc');
    }

    /**
     * Determine if the given recipient is set on the mailable.
     */
    public function hasCc(array|object|string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($address, $name, 'cc');
    }

    /**
     * Set the recipients of the message.
     */
    public function bcc(array|object|string $address, ?string $name = null): static
    {
        return $this->setAddress($address, $name, 'bcc');
    }

    /**
     * Determine if the given recipient is set on the mailable.
     */
    public function hasBcc(array|object|string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($address, $name, 'bcc');
    }

    /**
     * Set the "reply to" address of the message.
     */
    public function replyTo(array|object|string $address, ?string $name = null): static
    {
        return $this->setAddress($address, $name, 'replyTo');
    }

    /**
     * Determine if the given replyTo is set on the mailable.
     */
    public function hasReplyTo(array|object|string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($address, $name, 'replyTo');
    }

    /**
     * Set the recipients of the message.
     *
     * All recipients are stored internally as [['name' => ?, 'address' => ?]]
     */
    protected function setAddress(array|object|string $address, ?string $name = null, string $property = 'to'): static
    {
        if (empty($address)) {
            return $this;
        }

        foreach ($this->addressesToArray($address, $name) as $recipient) {
            $recipient = $this->normalizeRecipient($recipient);

            $this->{$property}[] = [
                'name' => $recipient->name ?? null,
                'address' => $recipient->email,
            ];
        }

        $this->{$property} = Collection::make($this->{$property})
            ->reverse()
            ->unique('address')
            ->reverse()
            ->values()
            ->all();

        return $this;
    }

    /**
     * Convert the given recipient arguments to an array.
     */
    protected function addressesToArray(array|object|string $address, ?string $name): array
    {
        if (! is_array($address) && ! $address instanceof Collection) {
            $address = is_string($name) ? [['name' => $name, 'email' => $address]] : [$address];
        }

        return $address instanceof Collection
            ? $address->toArray()
            : $address;
    }

    /**
     * Convert the given recipient into an object.
     */
    protected function normalizeRecipient(mixed $recipient): object
    {
        if (is_array($recipient)) {
            if (array_values($recipient) === $recipient) {
                return (object) array_map(function ($email) {
                    return compact('email');
                }, $recipient);
            }

            return (object) $recipient;
        }
        if (is_string($recipient)) {
            return (object) ['email' => $recipient];
        }
        if ($recipient instanceof Address) {
            return (object) ['email' => $recipient->getAddress(), 'name' => $recipient->getName()];
        }
        if ($recipient instanceof Mailables\Address) {
            return (object) ['email' => $recipient->address, 'name' => $recipient->name];
        }

        return $recipient;
    }

    /**
     * Determine if the given recipient is set on the mailable.
     */
    protected function hasRecipient(array|object|string $address, ?string $name = null, string $property = 'to'): bool
    {
        if (empty($address)) {
            return false;
        }

        $expected = $this->normalizeRecipient(
            $this->addressesToArray($address, $name)[0]
        );

        $expected = [
            'name' => $expected->name ?? null,
            'address' => $expected->email,
        ];

        if ($this->hasEnvelopeRecipient($expected['address'], $expected['name'], $property)) {
            return true;
        }

        return Collection::make($this->{$property})->contains(function ($actual) use ($expected) {
            if (! isset($expected['name'])) {
                return $actual['address'] == $expected['address'];
            }

            return $actual == $expected;
        });
    }

    /**
     * Determine if the mailable "envelope" method defines a recipient.
     */
    private function hasEnvelopeRecipient(string $address, ?string $name, string $property): bool
    {
        return method_exists($this, 'envelope') && match ($property) {
            'from' => $this->envelope()->isFrom($address, $name),
            'to' => $this->envelope()->hasTo($address, $name),
            'cc' => $this->envelope()->hasCc($address, $name),
            'bcc' => $this->envelope()->hasBcc($address, $name),
            'replyTo' => $this->envelope()->hasReplyTo($address, $name),
        };
    }

    /**
     * Set the subject of the message.
     */
    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Determine if the mailable has the given subject.
     */
    public function hasSubject(string $subject): bool
    {
        return $this->subject === $subject
            || (method_exists($this, 'envelope') && $this->envelope()->hasSubject($subject));
    }

    /**
     * Set the Markdown template for the message.
     */
    public function markdown(string $view, array $data = []): static
    {
        $this->markdown = $view;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    /**
     * Set the view and view data for the message.
     */
    public function view(string $view, array $data = []): static
    {
        $this->view = $view;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    /**
     * Set the rendered HTML content for the message.
     */
    public function html(string $html): static
    {
        $this->html = $html;

        return $this;
    }

    /**
     * Set the plain text view for the message.
     */
    public function text(string $textView, array $data = []): static
    {
        $this->textView = $textView;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    /**
     * Set the view data for the message.
     */
    public function with(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }

        return $this;
    }

    /**
     * Attach a file to the message.
     */
    public function attach(Attachable|Attachment|string $file, array $options = []): static
    {
        if ($file instanceof Attachable) {
            $file = $file->toMailAttachment();
        }

        if ($file instanceof Attachment) {
            return $file->attachTo($this, $options);
        }

        $this->attachments = Collection::make($this->attachments)
            ->push(compact('file', 'options'))
            ->unique('file')
            ->all();

        return $this;
    }

    /**
     * Attach multiple files to the message.
     */
    public function attachMany(array $files): static
    {
        foreach ($files as $file => $options) {
            if (is_int($file)) {
                $this->attach($options);
            } else {
                $this->attach($file, $options);
            }
        }

        return $this;
    }

    /**
     * Determine if the mailable has the given attachment.
     */
    public function hasAttachment(Attachable|Attachment|string $file, array $options = []): bool
    {
        if ($file instanceof Attachable) {
            $file = $file->toMailAttachment();
        }

        if ($file instanceof Attachment && $this->hasEnvelopeAttachment($file, $options)) {
            return true;
        }

        if ($file instanceof Attachment) {
            $parts = $file->attachWith(
                fn ($path) => [$path, [
                    'as' => $options['as'] ?? $file->as,
                    'mime' => $options['mime'] ?? $file->mime,
                ]],
                fn ($data) => $this->hasAttachedData($data(), $options['as'] ?? $file->as, ['mime' => $options['mime'] ?? $file->mime])
            );

            if ($parts === true) {
                return true;
            }

            [$file, $options] = $parts === false
                ? [null, []]
                : $parts;
        }

        return Collection::make($this->attachments)->contains(
            fn ($attachment) => $attachment['file'] === $file && array_filter($attachment['options']) === array_filter($options)
        );
    }

    /**
     * Determine if the mailable has the given envelope attachment.
     */
    private function hasEnvelopeAttachment(Attachment $attachment, array $options = []): bool
    {
        if (! method_exists($this, 'envelope')) {
            return false;
        }

        /* @phpstan-ignore-next-line */
        $attachments = $this->attachments();

        return Collection::make(is_object($attachments) ? [$attachments] : $attachments)
            ->map(fn ($attached) => $attached instanceof Attachable ? $attached->toMailAttachment() : $attached)
            ->contains(fn ($attached) => $attached->isEquivalent($attachment, $options));
    }

    /**
     * Attach a file to the message from storage.
     */
    public function attachFromStorage(string $path, ?string $name = null, array $options = []): static
    {
        return $this->attachFromStorageDisk(null, $path, $name, $options);
    }

    /**
     * Attach a file to the message from storage.
     */
    public function attachFromStorageDisk(?string $disk, string $path, ?string $name = null, array $options = []): static
    {
        $this->diskAttachments = Collection::make($this->diskAttachments)->push([
            'disk' => $disk,
            'path' => $path,
            'name' => $name ?? basename($path),
            'options' => $options,
        ])->unique(function ($file) {
            return $file['name'] . $file['disk'] . $file['path'];
        })->all();

        return $this;
    }

    /**
     * Determine if the mailable has the given attachment from storage.
     */
    public function hasAttachmentFromStorage(string $path, ?string $name = null, array $options = []): bool
    {
        return $this->hasAttachmentFromStorageDisk(null, $path, $name, $options);
    }

    /**
     * Determine if the mailable has the given attachment from a specific storage disk.
     */
    public function hasAttachmentFromStorageDisk(?string $disk, string $path, ?string $name = null, array $options = []): bool
    {
        return Collection::make($this->diskAttachments)->contains(
            fn ($attachment) => $attachment['disk'] === $disk
                && $attachment['path'] === $path
                && $attachment['name'] === ($name ?? basename($path))
                && $attachment['options'] === $options
        );
    }

    /**
     * Attach in-memory data as an attachment.
     */
    public function attachData(string $data, string $name, array $options = []): static
    {
        $this->rawAttachments = Collection::make($this->rawAttachments)
            ->push(compact('data', 'name', 'options'))
            ->unique(function ($file) {
                return $file['name'] . $file['data'];
            })->all();

        return $this;
    }

    /**
     * Determine if the mailable has the given data as an attachment.
     */
    public function hasAttachedData(string $data, ?string $name, array $options = []): bool
    {
        return Collection::make($this->rawAttachments)->contains(
            fn ($attachment) => $attachment['data'] === $data
                && $attachment['name'] === $name
                && array_filter($attachment['options']) === array_filter($options)
        );
    }

    /**
     * Add a tag header to the message when supported by the underlying transport.
     */
    public function tag(string $value): static
    {
        $this->tags[] = $value;

        return $this;
    }

    /**
     * Determine if the mailable has the given tag.
     */
    public function hasTag(string $value): bool
    {
        return in_array($value, $this->tags)
            || (method_exists($this, 'envelope') && in_array($value, $this->envelope()->tags));
    }

    /**
     * Add a metadata header to the message when supported by the underlying transport.
     */
    public function metadata(string $key, string $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Determine if the mailable has the given metadata.
     */
    public function hasMetadata(string $key, string $value): bool
    {
        return (isset($this->metadata[$key]) && $this->metadata[$key] === $value)
            || (method_exists($this, 'envelope') && $this->envelope()->hasMetadata($key, $value));
    }

    /**
     * Assert that the mailable is from the given address.
     */
    public function assertFrom(array|object|string $address, ?string $name = null): static
    {
        $this->renderForAssertions();

        $recipient = $this->formatAssertionRecipient($address, $name);

        PHPUnit::assertTrue(
            $this->hasFrom($address, $name),
            "Email was not from expected address [{$recipient}]."
        );

        return $this;
    }

    /**
     * Assert that the mailable has the given recipient.
     */
    public function assertTo(array|object|string $address, ?string $name = null): static
    {
        $this->renderForAssertions();

        $recipient = $this->formatAssertionRecipient($address, $name);

        PHPUnit::assertTrue(
            $this->hasTo($address, $name),
            "Did not see expected recipient [{$recipient}] in email 'to' recipients."
        );

        return $this;
    }

    /**
     * Assert that the mailable has the given recipient.
     */
    public function assertHasTo(array|object|string $address, ?string $name = null): static
    {
        return $this->assertTo($address, $name);
    }

    /**
     * Assert that the mailable has the given recipient.
     */
    public function assertHasCc(array|object|string $address, ?string $name = null): static
    {
        $this->renderForAssertions();

        $recipient = $this->formatAssertionRecipient($address, $name);

        PHPUnit::assertTrue(
            $this->hasCc($address, $name),
            "Did not see expected recipient [{$recipient}] in email 'cc' recipients."
        );

        return $this;
    }

    /**
     * Assert that the mailable has the given recipient.
     */
    public function assertHasBcc(array|object|string $address, ?string $name = null): static
    {
        $this->renderForAssertions();

        $recipient = $this->formatAssertionRecipient($address, $name);

        PHPUnit::assertTrue(
            $this->hasBcc($address, $name),
            "Did not see expected recipient [{$recipient}] in email 'bcc' recipients."
        );

        return $this;
    }

    /**
     * Assert that the mailable has the given "reply to" address.
     */
    public function assertHasReplyTo(array|object|string $address, ?string $name = null): static
    {
        $this->renderForAssertions();

        $replyTo = $this->formatAssertionRecipient($address, $name);

        PHPUnit::assertTrue(
            $this->hasReplyTo($address, $name),
            "Did not see expected address [{$replyTo}] as email 'reply to' recipient."
        );

        return $this;
    }

    /**
     * Format the mailable recipient for display in an assertion message.
     */
    private function formatAssertionRecipient(array|object|string $address, ?string $name = null): string
    {
        if (! is_string($address)) {
            $address = json_encode($address);
        }

        if (filled($name)) {
            $address .= ' (' . $name . ')';
        }

        return $address;
    }

    /**
     * Assert that the mailable has the given subject.
     */
    public function assertHasSubject(string $subject): static
    {
        $this->renderForAssertions();

        PHPUnit::assertTrue(
            $this->hasSubject($subject),
            "Did not see expected text [{$subject}] in email subject."
        );

        return $this;
    }

    /**
     * Assert that the given text is present in the HTML email body.
     */
    public function assertSeeInHtml(string $string, bool $escape = true): static
    {
        $string = $escape ? e($string) : $string;

        [$html, $text] = $this->renderForAssertions();

        PHPUnit::assertStringContainsString(
            $string,
            $html,
            "Did not see expected text [{$string}] within email body."
        );

        return $this;
    }

    /**
     * Assert that the given text is not present in the HTML email body.
     */
    public function assertDontSeeInHtml(string $string, bool $escape = true): static
    {
        $string = $escape ? e($string) : $string;

        [$html, $text] = $this->renderForAssertions();

        PHPUnit::assertStringNotContainsString(
            $string,
            $html,
            "Saw unexpected text [{$string}] within email body."
        );

        return $this;
    }

    /**
     * Assert that the given text strings are present in order in the HTML email body.
     */
    public function assertSeeInOrderInHtml(array $strings, bool $escape = true): static
    {
        $strings = $escape ? array_map('e', $strings) : $strings;

        [$html, $text] = $this->renderForAssertions();

        PHPUnit::assertThat($strings, new SeeInOrder($html));

        return $this;
    }

    /**
     * Assert that the given text is present in the plain-text email body.
     */
    public function assertSeeInText(string $string): static
    {
        [$html, $text] = $this->renderForAssertions();

        PHPUnit::assertStringContainsString(
            $string,
            $text,
            "Did not see expected text [{$string}] within text email body."
        );

        return $this;
    }

    /**
     * Assert that the given text is not present in the plain-text email body.
     */
    public function assertDontSeeInText(string $string): static
    {
        [$html, $text] = $this->renderForAssertions();

        PHPUnit::assertStringNotContainsString(
            $string,
            $text,
            "Saw unexpected text [{$string}] within text email body."
        );

        return $this;
    }

    /**
     * Assert that the given text strings are present in order in the plain-text email body.
     */
    public function assertSeeInOrderInText(array $strings): static
    {
        [$html, $text] = $this->renderForAssertions();

        PHPUnit::assertThat($strings, new SeeInOrder($text));

        return $this;
    }

    /**
     * Assert the mailable has the given attachment.
     */
    public function assertHasAttachment(Attachable|Attachment|string $file, array $options = []): static
    {
        $this->renderForAssertions();

        PHPUnit::assertTrue(
            $this->hasAttachment($file, $options),
            'Did not find the expected attachment.'
        );

        return $this;
    }

    /**
     * Assert the mailable has the given data as an attachment.
     */
    public function assertHasAttachedData(string $data, string $name, array $options = []): static
    {
        $this->renderForAssertions();

        PHPUnit::assertTrue(
            $this->hasAttachedData($data, $name, $options),
            'Did not find the expected attachment.'
        );

        return $this;
    }

    /**
     * Assert the mailable has the given attachment from storage.
     */
    public function assertHasAttachmentFromStorage(string $path, ?string $name = null, array $options = []): static
    {
        $this->renderForAssertions();

        PHPUnit::assertTrue(
            $this->hasAttachmentFromStorage($path, $name, $options),
            'Did not find the expected attachment.'
        );

        return $this;
    }

    /**
     * Assert the mailable has the given attachment from a specific storage disk.
     */
    public function assertHasAttachmentFromStorageDisk(string $disk, string $path, ?string $name = null, array $options = []): static
    {
        $this->renderForAssertions();

        PHPUnit::assertTrue(
            $this->hasAttachmentFromStorageDisk($disk, $path, $name, $options),
            'Did not find the expected attachment.'
        );

        return $this;
    }

    /**
     * Assert that the mailable has the given tag.
     */
    public function assertHasTag(string $tag): static
    {
        $this->renderForAssertions();

        PHPUnit::assertTrue(
            $this->hasTag($tag),
            "Did not see expected tag [{$tag}] in email tags."
        );

        return $this;
    }

    /**
     * Assert that the mailable has the given metadata.
     */
    public function assertHasMetadata(string $key, string $value): static
    {
        $this->renderForAssertions();

        PHPUnit::assertTrue(
            $this->hasMetadata($key, $value),
            "Did not see expected key [{$key}] and value [{$value}] in email metadata."
        );

        return $this;
    }

    /**
     * Render the HTML and plain-text version of the mailable into views for assertions.
     *
     * @throws ReflectionException
     */
    protected function renderForAssertions(): array
    {
        if ($this->assertionableRenderStrings) {
            return $this->assertionableRenderStrings;
        }

        return $this->assertionableRenderStrings = $this->withLocale($this->locale, function () {
            $this->prepareMailableForDelivery();

            $html = ApplicationContext::getContainer()
                ->get(Mailer::class)
                ->render(
                    $view = $this->buildView(),
                    $this->buildViewData()
                );

            if (is_array($view) && isset($view[1])) {
                $text = $view[1];
            }

            $text ??= $view['text'] ?? '';

            if (! empty($text) && ! $text instanceof Htmlable) {
                $text = ApplicationContext::getContainer()
                    ->get(Mailer::class)->render(
                        $text,
                        $this->buildViewData()
                    );
            }

            return [(string) $html, (string) $text];
        });
    }

    /**
     * Prepare the mailable instance for delivery.
     */
    protected function prepareMailableForDelivery(): void
    {
        if (method_exists($this, 'build')) {
            $container = ApplicationContext::getContainer();
            method_exists($container, 'call')
                ? $container->call([$this, 'build']) // @phpstan-ignore-line
                : call([$this, 'build']);
        }

        $this->ensureHeadersAreHydrated();
        $this->ensureEnvelopeIsHydrated();
        $this->ensureContentIsHydrated();
        $this->ensureAttachmentsAreHydrated();
    }

    /**
     * Ensure the mailable's headers are hydrated from the "headers" method.
     */
    private function ensureHeadersAreHydrated(): void
    {
        if (! method_exists($this, 'headers')) {
            return;
        }

        $headers = $this->headers();

        $this->withSymfonyMessage(function ($message) use ($headers) {
            if ($headers->messageId) {
                $message->getHeaders()->addIdHeader('Message-Id', $headers->messageId);
            }

            if (count($headers->references) > 0) {
                $message->getHeaders()->addTextHeader('References', $headers->referencesString());
            }

            foreach ($headers->text as $key => $value) {
                $message->getHeaders()->addTextHeader($key, $value);
            }
        });
    }

    /**
     * Ensure the mailable's "envelope" data is hydrated from the "envelope" method.
     */
    private function ensureEnvelopeIsHydrated(): void
    {
        if (! method_exists($this, 'envelope')) {
            return;
        }

        $envelope = $this->envelope();

        if (isset($envelope->from)) {
            $this->from($envelope->from->address, $envelope->from->name);
        }

        foreach (['to', 'cc', 'bcc', 'replyTo'] as $type) {
            foreach ($envelope->{$type} as $address) {
                $this->{$type}($address->address, $address->name);
            }
        }

        if ($envelope->subject) {
            $this->subject($envelope->subject);
        }

        foreach ($envelope->tags as $tag) {
            $this->tag($tag);
        }

        foreach ($envelope->metadata as $key => $value) {
            $this->metadata($key, $value);
        }

        foreach ($envelope->using as $callback) {
            $this->withSymfonyMessage($callback);
        }
    }

    /**
     * Ensure the mailable's content is hydrated from the "content" method.
     */
    private function ensureContentIsHydrated(): void
    {
        if (! method_exists($this, 'content')) {
            return;
        }

        $content = $this->content();

        if ($content->view) {
            $this->view($content->view);
        }

        if ($content->html) {
            $this->view($content->html);
        }

        if ($content->text) {
            $this->text($content->text);
        }

        if ($content->markdown) {
            $this->markdown($content->markdown);
        }

        if ($content->htmlString) {
            $this->html($content->htmlString);
        }

        foreach ($content->with as $key => $value) {
            $this->with($key, $value);
        }
    }

    /**
     * Ensure the mailable's attachments are hydrated from the "attachments" method.
     */
    private function ensureAttachmentsAreHydrated(): void
    {
        if (! method_exists($this, 'attachments')) {
            return;
        }

        /* @phpstan-ignore-next-line */
        $attachments = $this->attachments();

        Collection::make(is_object($attachments) ? [$attachments] : $attachments)
            ->each(function ($attachment) {
                $this->attach($attachment);
            });
    }

    /**
     * Set the name of the mailer that should send the message.
     */
    public function mailer(string $mailer): static
    {
        $this->mailer = $mailer;

        return $this;
    }

    /**
     * Register a callback to be called with the Symfony message instance.
     */
    public function withSymfonyMessage(callable $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be called while building the view data.
     */
    public static function buildViewDataUsing(callable $callback): void
    {
        static::$viewDataCallback = $callback;
    }

    /**
     * Dynamically bind parameters to the message.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (str_starts_with($method, 'with')) {
            return $this->with(Str::camel(substr($method, 4)), $parameters[0]);
        }

        static::throwBadMethodCallException($method);
    }
}
