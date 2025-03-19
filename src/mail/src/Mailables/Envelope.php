<?php

declare(strict_types=1);

namespace Hypervel\Mail\Mailables;

use Closure;
use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\Conditionable\Conditionable;

class Envelope
{
    use Conditionable;

    /**
     * The address sending the message.
     */
    public null|Address|string $from;

    /**
     * The recipients of the message.
     */
    public array $to;

    /**
     * The recipients receiving a copy of the message.
     */
    public array $cc;

    /**
     * The recipients receiving a blind copy of the message.
     */
    public array $bcc;

    /**
     * The recipients that should be replied to.
     */
    public array $replyTo;

    /**
     * The subject of the message.
     */
    public ?string $subject;

    /**
     * The message's tags.
     */
    public array $tags = [];

    /**
     * The message's meta data.
     */
    public array $metadata = [];

    /**
     * The message's Symfony Message customization callbacks.
     */
    public array $using = [];

    /**
     * Create a new message envelope instance.
     *
     * @param array<int, Address|string> $to
     * @param array<int, Address|string> $cc
     * @param array<int, Address|string> $bcc
     * @param array<int, Address|string> $replyTo
     *
     * @named-arguments-supported
     */
    public function __construct(null|Address|string $from = null, $to = [], $cc = [], $bcc = [], $replyTo = [], ?string $subject = null, array $tags = [], array $metadata = [], array|Closure $using = [])
    {
        $this->from = is_string($from) ? new Address($from) : $from;
        $this->to = $this->normalizeAddresses($to);
        $this->cc = $this->normalizeAddresses($cc);
        $this->bcc = $this->normalizeAddresses($bcc);
        $this->replyTo = $this->normalizeAddresses($replyTo);
        $this->subject = $subject;
        $this->tags = $tags;
        $this->metadata = $metadata;
        $this->using = Arr::wrap($using);
    }

    /**
     * Specify who the message will be "from".
     */
    public function from(Address|string $address, ?string $name = null): static
    {
        $this->from = is_string($address) ? new Address($address, $name) : $address;

        return $this;
    }

    /**
     * Add a "to" recipient to the message envelope.
     */
    public function to(Address|array|string $address, ?string $name = null): static
    {
        $this->to = array_merge($this->to, $this->normalizeAddresses(
            is_string(
                $name
            ) ? [new Address($address, $name)] : Arr::wrap($address),
        ));

        return $this;
    }

    /**
     * Add a "cc" recipient to the message envelope.
     */
    public function cc(Address|array|string $address, ?string $name = null): static
    {
        $this->cc = array_merge($this->cc, $this->normalizeAddresses(
            is_string($name) ? [new Address($address, $name)] : Arr::wrap($address),
        ));

        return $this;
    }

    /**
     * Add a "bcc" recipient to the message envelope.
     */
    public function bcc(Address|array|string $address, ?string $name = null): static
    {
        $this->bcc = array_merge($this->bcc, $this->normalizeAddresses(
            is_string($name) ? [new Address($address, $name)] : Arr::wrap($address),
        ));

        return $this;
    }

    /**
     * Add a "reply to" recipient to the message envelope.
     */
    public function replyTo(Address|array|string $address, ?string $name = null): static
    {
        $this->replyTo = array_merge($this->replyTo, $this->normalizeAddresses(
            is_string($name) ? [new Address($address, $name)] : Arr::wrap($address),
        ));

        return $this;
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
     * Add "tags" to the message.
     */
    public function tags(array $tags): static
    {
        $this->tags = array_merge($this->tags, $tags);

        return $this;
    }

    /**
     * Add a "tag" to the message.
     *
     * @return $this
     */
    public function tag(string $tag): static
    {
        $this->tags[] = $tag;

        return $this;
    }

    /**
     * Add metadata to the message.
     *
     * @return $this
     */
    public function metadata(string $key, int|string $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Add a Symfony Message customization callback to the message.
     *
     * @return $this
     */
    public function using(Closure $callback): static
    {
        $this->using[] = $callback;

        return $this;
    }

    /**
     * Determine if the message is from the given address.
     */
    public function isFrom(string $address, ?string $name = null): bool
    {
        if (is_null($name)) {
            return $this->from->address === $address;
        }

        return $this->from->address === $address
            && $this->from->name === $name;
    }

    /**
     * Determine if the message has the given address as a recipient.
     */
    public function hasTo(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->to, $address, $name);
    }

    /**
     * Determine if the message has the given address as a "cc" recipient.
     */
    public function hasCc(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->cc, $address, $name);
    }

    /**
     * Determine if the message has the given address as a "bcc" recipient.
     */
    public function hasBcc(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->bcc, $address, $name);
    }

    /**
     * Determine if the message has the given address as a "reply to" recipient.
     */
    public function hasReplyTo(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->replyTo, $address, $name);
    }

    /**
     * Determine if the message has the given subject.
     */
    public function hasSubject(string $subject): bool
    {
        return $this->subject === $subject;
    }

    /**
     * Determine if the message has the given metadata.
     */
    public function hasMetadata(string $key, string $value): bool
    {
        return isset($this->metadata[$key]) && (string) $this->metadata[$key] === $value;
    }

    /**
     * Normalize the given array of addresses.
     *
     * @param array<int, Address|string> $addresses
     * @return array<int, Address>
     */
    protected function normalizeAddresses(array $addresses): array
    {
        return Collection::make($addresses)->map(function ($address) {
            return is_string($address) ? new Address($address) : $address;
        })->all();
    }

    /**
     * Determine if the message has the given recipient.
     */
    protected function hasRecipient(array $recipients, string $address, ?string $name = null): bool
    {
        return Collection::make($recipients)->contains(function ($recipient) use ($address, $name) {
            if (is_null($name)) {
                return $recipient->address === $address;
            }

            return $recipient->address === $address
                && $recipient->name === $name;
        });
    }
}
