<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

class DummyDto
{
    /**
     * Create a song transfer object.
     */
    public function __construct(
        public string $artist,
        public string $name,
        public int $year
    ) {
    }

    /**
     * Create Rick Astley's song.
     */
    public static function rick(): static
    {
        return new static('Rick Astley', 'Never gonna give you up', 1987);
    }

    /**
     * Create Bon Jovi's song.
     */
    public static function bon(): static
    {
        return new static('Bon Jovi', 'Living on a prayer', 1986);
    }
}
