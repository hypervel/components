<?php

declare(strict_types=1);

namespace Hypervel\Ai\Classification;

use Hypervel\Ai\Contracts\Question;
use InvalidArgumentException;

final readonly class Choice implements Question
{
    /**
     * Create a new single-choice question whose answer is one of the given options.
     *
     * @param array<string, mixed>|string $instructions
     * @param array<array-key, null|array<string, mixed>|string> $options option names mapped to an optional description
     *
     * @throws InvalidArgumentException if fewer than two options are given
     */
    public function __construct(
        public string|array $instructions,
        public array $options,
    ) {
        if (count($options) < 2) {
            throw new InvalidArgumentException('A choice question requires at least two options.');
        }
    }

    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'choice',
            'instructions' => $this->instructions,
            'options' => $this->options,
        ];
    }
}
