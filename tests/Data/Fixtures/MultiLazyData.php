<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;
use Hypervel\Data\Lazy;

class MultiLazyData extends Data
{
    /**
     * Create a fixture with three lazy properties.
     */
    public function __construct(
        public string|Lazy $artist,
        public string|Lazy $name,
        public int|Lazy $year,
    ) {
    }

    /**
     * Create the fixture from separate values.
     */
    public static function fromMultiple(string $artist, string $name, int $year): static
    {
        return new static(
            Lazy::create(fn (): string => $artist),
            Lazy::create(fn (): string => $name),
            Lazy::create(fn (): int => $year),
        );
    }

    /**
     * Create the fixture from a song transfer object.
     */
    public static function fromDto(DummyDto $dto): static
    {
        return new static(
            Lazy::create(fn (): string => $dto->artist),
            Lazy::create(fn (): string => $dto->name),
            Lazy::create(fn (): int => $dto->year),
        );
    }
}
