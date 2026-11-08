<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Messages;

use Hypervel\Container\Container;
use Hypervel\Contracts\Mail\Attachable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Renderable;
use Hypervel\Mail\Attachment;
use Hypervel\Mail\Markdown;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Conditionable;

class MailMessage extends SimpleMessage implements Renderable
{
    use Conditionable;

    /**
     * The view to be rendered.
     */
    public array|string|null $view = null;

    /**
     * The view data for the message.
     */
    public array $viewData = [];

    /**
     * The Markdown template to render (if applicable).
     */
    public ?string $markdown = 'notifications::email';

    /**
     * The current theme being used when generating emails.
     */
    public ?string $theme = null;

    /**
     * The "from" information for the message.
     */
    public array $from = [];

    /**
     * The "reply to" information for the message.
     */
    public array $replyTo = [];

    /**
     * The "cc" information for the message.
     */
    public array $cc = [];

    /**
     * The "bcc" information for the message.
     */
    public array $bcc = [];

    /**
     * The attachments for the message.
     */
    public array $attachments = [];

    /**
     * The raw attachments for the message.
     */
    public array $rawAttachments = [];

    /**
     * The tags for the message.
     */
    public array $tags = [];

    /**
     * The metadata for the message.
     *
     * @var array<string, int|string>
     */
    public array $metadata = [];

    /**
     * Priority level of the message.
     */
    public ?int $priority = null;

    /**
     * The callbacks for the message.
     */
    public array $callbacks = [];

    /**
     * Set the view for the mail message.
     *
     * @return $this
     */
    public function view(array|string $view, array $data = []): static
    {
        $this->view = $view;
        $this->viewData = $data;

        $this->markdown = null;

        return $this;
    }

    /**
     * Set the plain text view for the mail message.
     *
     * @return $this
     */
    public function text(string $textView, array $data = []): static
    {
        return $this->view([
            'html' => is_array($this->view) ? ($this->view['html'] ?? null) : $this->view,
            'text' => $textView,
        ], $data);
    }

    /**
     * Set the Markdown template for the notification.
     *
     * @return $this
     */
    public function markdown(string $view, array $data = []): static
    {
        $this->markdown = $view;
        $this->viewData = $data;

        $this->view = null;

        return $this;
    }

    /**
     * Set the default markdown template.
     *
     * @return $this
     */
    public function template(string $template): static
    {
        $this->markdown = $template;

        return $this;
    }

    /**
     * Set the theme to use with the Markdown template.
     *
     * @return $this
     */
    public function theme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    /**
     * Set the from address for the mail message.
     *
     * @return $this
     */
    public function from(string $address, ?string $name = null): static
    {
        $this->from = [$address, $name];

        return $this;
    }

    /**
     * Set the "reply to" address of the message.
     *
     * @return $this
     */
    public function replyTo(mixed $address, ?string $name = null): static
    {
        if ($this->arrayOfAddresses($address)) {
            $this->replyTo = array_merge($this->replyTo, $this->parseAddresses($address));
        } else {
            $this->replyTo[] = [$address, $name];
        }

        return $this;
    }

    /**
     * Set the cc address for the mail message.
     *
     * @return $this
     */
    public function cc(mixed $address, ?string $name = null): static
    {
        if ($this->arrayOfAddresses($address)) {
            $this->cc = array_merge($this->cc, $this->parseAddresses($address));
        } else {
            $this->cc[] = [$address, $name];
        }

        return $this;
    }

    /**
     * Set the bcc address for the mail message.
     *
     * @return $this
     */
    public function bcc(mixed $address, ?string $name = null): static
    {
        if ($this->arrayOfAddresses($address)) {
            $this->bcc = array_merge($this->bcc, $this->parseAddresses($address));
        } else {
            $this->bcc[] = [$address, $name];
        }

        return $this;
    }

    /**
     * Attach a file to the message.
     *
     * @return $this
     */
    public function attach(Attachable|Attachment|string $file, array $options = []): static
    {
        if ($file instanceof Attachable) {
            $file = $file->toMailAttachment();
        }

        if ($file instanceof Attachment) {
            return $file->attachTo($this);
        }

        $this->attachments[] = ['file' => $file, 'options' => $options];

        return $this;
    }

    /**
     * Attach multiple files to the message.
     *
     * @param array<array|Attachable|Attachment|string> $files
     * @return $this
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
     * Attach in-memory data as an attachment.
     *
     * @return $this
     */
    public function attachData(string $data, string $name, array $options = []): static
    {
        $this->rawAttachments[] = ['data' => $data, 'name' => $name, 'options' => $options];

        return $this;
    }

    /**
     * Attach a file to the message from storage.
     *
     * @return $this
     */
    public function attachFromStorage(
        string $path,
        ?string $name = null,
        array $options = []
    ): static {
        return $this->attachFromStorageDisk(null, $path, $name, $options);
    }

    /**
     * Attach a file to the message from storage.
     *
     * @return $this
     */
    public function attachFromStorageDisk(
        ?string $disk,
        string $path,
        ?string $name = null,
        array $options = []
    ): static {
        $attachment = Attachment::fromStorageDisk($disk, $path);

        if ($name !== null) {
            $attachment->as($name);
        }

        if (isset($options['mime'])) {
            $attachment->withMime($options['mime']);
        }

        return $this->attach($attachment);
    }

    /**
     * Add a tag header to the message when supported by the underlying transport.
     *
     * @return $this
     */
    public function tag(string $value): static
    {
        $this->tags[] = $value;

        return $this;
    }

    /**
     * Add a metadata header to the message when supported by the underlying transport.
     *
     * @return $this
     */
    public function metadata(string $key, int|string $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Set the priority of this message.
     *
     * The value is an integer where 1 is the highest priority and 5 is the lowest.
     *
     * @return $this
     */
    public function priority(int $level): static
    {
        $this->priority = $level;

        return $this;
    }

    /**
     * Get the data array for the mail message.
     */
    public function data(): array
    {
        return array_merge($this->toArray(), $this->viewData);
    }

    /**
     * Parse the multi-address array into the necessary format.
     */
    protected function parseAddresses(Arrayable|iterable $value): array
    {
        return (new Collection($value))
            ->map(fn (mixed $address, int|string $name): array => [$address, is_numeric($name) ? null : $name])
            ->values()
            ->all();
    }

    /**
     * Determine if the given "address" is actually an array of addresses.
     */
    protected function arrayOfAddresses(mixed $address): bool
    {
        return is_iterable($address) || $address instanceof Arrayable;
    }

    /**
     * Render the mail notification message into an HTML string.
     */
    public function render(): string
    {
        if (isset($this->view)) {
            return Container::getInstance()
                ->make('mailer')
                ->render(
                    $this->view,
                    $this->data()
                );
        }

        $markdown = Container::getInstance()
            ->make(Markdown::class);

        return (string) $markdown->render(
            $this->markdown,
            $this->data(),
            theme: $this->theme ?: $markdown->getTheme()
        );
    }

    /**
     * Register a callback to be called with the Symfony message instance.
     *
     * @return $this
     */
    public function withSymfonyMessage(callable $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }
}
