<?php

declare(strict_types=1);

namespace Hypervel\Ai\Classification;

use Hypervel\Ai\Contracts\Question;
use InvalidArgumentException;

final readonly class Boolean implements Question
{
    /**
     * Create a new yes / no question whose answer is the probability of "true".
     *
     * @param array<string, mixed>|string $instructions
     * @param null|array{true?: string, false?: string} $criteria descriptions of what a yes and a no mean
     *
     * @throws InvalidArgumentException if the criteria describe anything but the true and false cases
     */
    public function __construct(
        public string|array $instructions,
        public ?array $criteria = null,
    ) {
        if ($criteria !== null && array_diff(array_keys($criteria), ['true', 'false']) !== []) {
            throw new InvalidArgumentException('Boolean criteria may only describe the "true" and "false" cases.');
        }
    }

    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => 'boolean',
            'instructions' => $this->instructions,
            'criteria' => $this->criteria,
        ], fn (mixed $value): bool => $value !== null);
    }
}
