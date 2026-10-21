<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use DateTime;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Optional;
use Hypervel\Support\CarbonImmutable;

class ComplicatedData extends Data
{
    /**
     * Create a fixture covering the default property types.
     * @param mixed $withoutType
     * @param mixed $explicitCast
     */
    public function __construct(
        public $withoutType,
        public int $int,
        public bool $bool,
        public float $float,
        public string $string,
        public array $array,
        public ?int $nullable,
        public int|Optional $undefinable,
        public mixed $mixed,
        #[WithCast(DateTimeInterfaceCast::class, format: 'd-m-Y', type: CarbonImmutable::class)]
        public $explicitCast,
        public DateTime $defaultCast,
        public ?SimpleData $nestedData,
        /** @var SimpleData[] */
        public ?DataCollection $nestedCollection,
        #[DataCollectionOf(SimpleData::class)]
        public array $nestedArray,
    ) {
    }
}
