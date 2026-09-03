<?php

declare(strict_types=1);

namespace Hypervel\Data\Transformers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hypervel\Container\Container;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;

class DateTimeInterfaceTransformer implements Transformer
{
    protected string $format;

    protected ?DateTimeZone $timeZone;

    /**
     * Create a date transformer.
     */
    public function __construct(
        ?string $format = null,
        ?string $setTimeZone = null,
    ) {
        if ($format === null || $setTimeZone === null) {
            $config = Container::getInstance()->make(DataConfig::class);

            $format ??= $config->dateFormats[0];
            $setTimeZone ??= $config->dateTimezone;
        }

        $this->format = $format;
        $this->timeZone = $setTimeZone === null ? null : new DateTimeZone($setTimeZone);
    }

    /**
     * Transform a date value.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): string
    {
        /** @var DateTimeInterface $value */
        if ($this->timeZone !== null) {
            $value = DateTimeImmutable::createFromInterface($value)->setTimezone($this->timeZone);
        }

        return $value->format(ltrim($this->format, '!'));
    }
}
