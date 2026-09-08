<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Contracts\Mail\Attachable;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\ForwardsCalls;
use InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * @mixin \Symfony\Component\Mime\Email
 */
class Message
{
    use ForwardsCalls;

    /**
     * Create a new message instance.
     */
    public function __construct(
        protected Email $message
    ) {
    }

    /**
     * Add a "from" address to the message.
     */
    public function from(array|string $address, ?string $name = null): static
    {
        is_array($address)
            ? $this->message->from(...$this->ensureAddressesAreSafe($address))
            : $this->message->from($this->createAddress($address, (string) $name));

        return $this;
    }

    /**
     * Set the "sender" of the message.
     */
    public function sender(array|string $address, ?string $name = null): static
    {
        is_array($address)
            ? $this->message->sender(...$this->ensureAddressesAreSafe($address))
            : $this->message->sender($this->createAddress($address, (string) $name));

        return $this;
    }

    /**
     * Set the "return path" of the message.
     */
    public function returnPath(Address|string $address): static
    {
        $this->ensureAddressIsSafe($address);

        $this->message->returnPath($address);

        return $this;
    }

    /**
     * Add a recipient to the message.
     */
    public function to(array|string $address, ?string $name = null, bool $override = false): static
    {
        if ($override) {
            is_array($address)
                ? $this->message->to(...$this->ensureAddressesAreSafe($address))
                : $this->message->to($this->createAddress($address, (string) $name));

            return $this;
        }

        return $this->addAddresses($address, $name, 'To');
    }

    /**
     * Remove all "to" addresses from the message.
     */
    public function forgetTo(): static
    {
        if ($header = $this->message->getHeaders()->get('To')) {
            $this->addAddressDebugHeader('X-To', $this->message->getTo());

            /* @phpstan-ignore-next-line */
            $header->setAddresses([]);
        }

        return $this;
    }

    /**
     * Add a carbon copy to the message.
     */
    public function cc(array|string $address, ?string $name = null, bool $override = false): static
    {
        if ($override) {
            is_array($address)
                ? $this->message->cc(...$this->ensureAddressesAreSafe($address))
                : $this->message->cc($this->createAddress($address, (string) $name));

            return $this;
        }

        return $this->addAddresses($address, $name, 'Cc');
    }

    /**
     * Remove all carbon copy addresses from the message.
     */
    public function forgetCc(): static
    {
        if ($header = $this->message->getHeaders()->get('Cc')) {
            $this->addAddressDebugHeader('X-Cc', $this->message->getCC());

            /* @phpstan-ignore-next-line */
            $header->setAddresses([]);
        }

        return $this;
    }

    /**
     * Add a blind carbon copy to the message.
     */
    public function bcc(array|string $address, ?string $name = null, bool $override = false): static
    {
        if ($override) {
            is_array($address)
                ? $this->message->bcc(...$this->ensureAddressesAreSafe($address))
                : $this->message->bcc($this->createAddress($address, (string) $name));

            return $this;
        }

        return $this->addAddresses($address, $name, 'Bcc');
    }

    /**
     * Remove all of the blind carbon copy addresses from the message.
     */
    public function forgetBcc(): static
    {
        if ($header = $this->message->getHeaders()->get('Bcc')) {
            $this->addAddressDebugHeader('X-Bcc', $this->message->getBcc());

            /* @phpstan-ignore-next-line */
            $header->setAddresses([]);
        }

        return $this;
    }

    /**
     * Add a "reply to" address to the message.
     */
    public function replyTo(array|string $address, ?string $name = null): static
    {
        return $this->addAddresses($address, $name, 'ReplyTo');
    }

    /**
     * Add a recipient to the message.
     */
    protected function addAddresses(array|string $address, ?string $name, string $type): static
    {
        if (is_array($address)) {
            $type = lcfirst($type);

            $addresses = (new Collection($address))->map(function (Address|array|string|null $address, int|string $key): Address|string {
                if (is_string($key) && is_string($address)) {
                    return $this->createAddress($key, $address);
                }

                if (is_array($address)) {
                    return $this->createAddress($address['email'] ?? $address['address'], $address['name'] ?? null);
                }

                if (is_null($address)) {
                    return $this->createAddress($key);
                }

                return $this->ensureAddressIsSafe($address);
            })->all();

            $this->message->{"{$type}"}(...$addresses);
        } else {
            $this->message->{"add{$type}"}($this->createAddress($address, (string) $name));
        }

        return $this;
    }

    /**
     * Create a safe Symfony address instance.
     */
    protected function createAddress(string $address, ?string $name = null): Address
    {
        $this->ensureAddressIsSafe($address);

        return new Address($address, (string) $name);
    }

    /**
     * Ensure the given address cannot inject additional headers or commands.
     */
    protected function ensureAddressIsSafe(Address|string $address): Address|string
    {
        // Check raw strings before Symfony trims them; constructed Address instances are already validated.
        if (is_string($address) && preg_match('/[\r\n]/', $address) > 0) {
            throw new InvalidArgumentException('Email addresses may not contain line break characters.');
        }

        return $address;
    }

    /**
     * Ensure the given addresses cannot inject additional headers or commands.
     *
     * @param array<Address|string> $addresses
     * @return array<Address|string>
     */
    protected function ensureAddressesAreSafe(array $addresses): array
    {
        return array_map(fn (Address|string $address): Address|string => $this->ensureAddressIsSafe($address), $addresses);
    }

    /**
     * Add an address debug header for a list of recipients.
     *
     * @param \Symfony\Component\Mime\Address[] $addresses
     */
    protected function addAddressDebugHeader(string $header, array $addresses): static
    {
        $this->message->getHeaders()->addTextHeader(
            $header,
            implode(', ', array_map(fn ($a) => $a->toString(), $addresses)),
        );

        return $this;
    }

    /**
     * Set the subject of the message.
     */
    public function subject(string $subject): static
    {
        $this->message->subject($subject);

        return $this;
    }

    /**
     * Set the message priority level.
     */
    public function priority(int $level): static
    {
        $this->message->priority($level);

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
            return $file->attachTo($this);
        }

        $this->message->attachFromPath($file, $options['as'] ?? null, $options['mime'] ?? null);

        return $this;
    }

    /**
     * Attach in-memory data as an attachment.
     *
     * @param resource|string $data
     */
    public function attachData(mixed $data, string $name, array $options = []): static
    {
        $this->message->attach($data, $name, $options['mime'] ?? null);

        return $this;
    }

    /**
     * Embed a file in the message and get the CID.
     */
    public function embed(Attachable|Attachment|string $file): string
    {
        if ($file instanceof Attachable) {
            $file = $file->toMailAttachment();
        }

        if ($file instanceof Attachment) {
            return $file->attachWith(
                function ($path) use ($file) {
                    $part = (new DataPart(new File($path), $file->as, $file->mime))->asInline();

                    $this->message->addPart($part);

                    return "cid:{$part->getContentId()}";
                },
                function ($data) use ($file) {
                    $this->message->addPart(
                        $part = (new DataPart($data(), $file->as, $file->mime))->asInline()
                    );

                    return "cid:{$part->getContentId()}";
                }
            );
        }

        $fileObject = new File($file);

        $this->message->addPart(
            $part = (new DataPart($fileObject, $fileObject->getFilename()))->asInline()
        );

        return "cid:{$part->getContentId()}";
    }

    /**
     * Embed in-memory data in the message and get the CID.
     *
     * @param resource|string $data
     */
    public function embedData(mixed $data, string $name, ?string $contentType = null): string
    {
        $part = (new DataPart($data, $name, $contentType))->asInline();

        $this->message->addPart($part);

        return "cid:{$part->getContentId()}";
    }

    /**
     * Get the underlying Symfony Email instance.
     */
    public function getSymfonyMessage(): Email
    {
        return $this->message;
    }

    /**
     * Dynamically pass missing methods to the Symfony instance.
     */
    public function __call(string $method, array $parameters)
    {
        return $this->forwardDecoratedCallTo($this->message, $method, $parameters);
    }
}
