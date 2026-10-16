<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use ArrayAccess;
use Countable;
use Hypervel\Ai\Responses\Data\Answer;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

class ClassificationResponse implements Arrayable, ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new classification response instance.
     *
     * @param array<array-key, Answer> $answers
     */
    public function __construct(
        public readonly array $answers,
        public readonly TextUsage $usage,
        public readonly Meta $meta,
    ) {
    }

    /**
     * Get the answer for the given question key.
     *
     * @throws InvalidArgumentException if no answer exists for the key
     */
    public function answer(string|int $key): Answer
    {
        return $this->answers[$key] ?? throw new InvalidArgumentException("No answer was returned for question [{$key}].");
    }

    /**
     * Get the answers as a collection.
     *
     * @return Collection<array-key, Answer>
     */
    public function collect(): Collection
    {
        return new Collection($this->answers);
    }

    /**
     * Get the number of answers in the response.
     */
    public function count(): int
    {
        return count($this->answers);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'answers' => $this->answers,
            'usage' => $this->usage,
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get an iterator for the answers.
     *
     * @return Traversable<array-key, Answer>
     */
    public function getIterator(): Traversable
    {
        yield from $this->answers;
    }

    /**
     * Determine if an answer exists for the given key.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->answers[$offset]);
    }

    /**
     * Get the answer for the given key.
     */
    public function offsetGet(mixed $offset): Answer
    {
        return $this->answer($offset);
    }

    /**
     * Reject writes to the immutable answers.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Classification answers are read-only.');
    }

    /**
     * Reject removal of immutable answers.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Classification answers are read-only.');
    }
}
