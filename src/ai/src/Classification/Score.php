<?php

declare(strict_types=1);

namespace Hypervel\Ai\Classification;

use Hypervel\Ai\Contracts\Question;
use InvalidArgumentException;

final readonly class Score implements Question
{
    /**
     * Create a new ordinal question whose answer is the expected position on the given levels.
     *
     * @param array<string, mixed>|string $instructions
     * @param list<array<string, mixed>|string> $levels level descriptions ordered from lowest to highest
     *
     * @throws InvalidArgumentException if the levels are not a list of at least two entries
     */
    public function __construct(
        public string|array $instructions,
        public array $levels,
    ) {
        if (! array_is_list($levels) || count($levels) < 2) {
            throw new InvalidArgumentException('A score question requires a list of at least two levels.');
        }
    }

    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'score',
            'instructions' => $this->instructions,
            'levels' => $this->levels,
        ];
    }
}
