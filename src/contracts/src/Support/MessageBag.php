<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

use Countable;

interface MessageBag extends Arrayable, Countable
{
    /**
     * Get the keys present in the message bag.
     */
    public function keys(): array;

    /**
     * Add a message to the bag.
     *
     * @return $this
     */
    public function add(string $key, string $message): static;

    /**
     * Merge a new array of messages into the bag.
     *
     * @return $this
     */
    public function merge(MessageProvider|array $messages): static;

    /**
     * Determine if messages exist for a given key.
     */
    public function has(array|string|null $key): bool;

    /**
     * Get the first message from the bag for a given key.
     */
    public function first(?string $key = null, ?string $format = null): string;

    /**
     * Get all of the messages from the bag for a given key.
     */
    public function get(string $key, ?string $format = null): array;

    /**
     * Get all of the messages for every key in the bag.
     */
    public function all(?string $format = null): array;

    /**
     * Remove a message from the bag.
     *
     * @return $this
     */
    public function forget(string $key): static;

    /**
     * Get the raw messages in the container.
     */
    public function getMessages(): array;

    /**
     * Get the default message format.
     */
    public function getFormat(): string;

    /**
     * Set the default message format.
     *
     * @return $this
     */
    public function setFormat(string $format = ':message'): static;

    /**
     * Determine if the message bag has any messages.
     */
    public function isEmpty(): bool;

    /**
     * Determine if the message bag has any messages.
     */
    public function isNotEmpty(): bool;
}
