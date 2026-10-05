<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Transformers;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\DateTimeInterfaceTransformer;
use Hypervel\Support\Carbon as HypervelCarbon;
use Hypervel\Support\CarbonImmutable as HypervelCarbonImmutable;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class DateTimeInterfaceTransformerTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanTransformDates(): void
    {
        $transformer = new DateTimeInterfaceTransformer;

        foreach ($this->dates() as $date) {
            $this->assertSame('1994-05-19T00:00:00+00:00', $this->transform($transformer, $date));
        }
    }

    public function testCanTransformDatesWithAnAlternativeFormat(): void
    {
        $transformer = new DateTimeInterfaceTransformer(format: 'd-m-Y');

        foreach ($this->dates() as $date) {
            $this->assertSame('19-05-1994', $this->transform($transformer, $date));
        }
    }

    public function testCanChangeTheTimezone(): void
    {
        $transformer = new DateTimeInterfaceTransformer(setTimeZone: 'Europe/Brussels');

        foreach ($this->dates() as $date) {
            $this->assertSame('1994-05-19T02:00:00+02:00', $this->transform($transformer, $date));
            // The transformed copy changes timezone; the property's value does not.
            $this->assertSame('UTC', $date->getTimezone()->getName());
        }
    }

    #[WithConfig('data.date_format', '!Y-m-d')]
    public function testCanTransformDatesWithLeadingExclamationMark(): void
    {
        $transformer = new DateTimeInterfaceTransformer;
        $date = Carbon::createFromFormat('!Y-m-d', '1994-05-19', new DateTimeZone('UTC'));

        $this->assertSame('1994-05-19', $this->transform($transformer, $date));
    }

    /**
     * Transform one date.
     */
    protected function transform(
        DateTimeInterfaceTransformer $transformer,
        DateTimeInterface $date,
    ): string {
        return $transformer->transform(
            m::mock(DataProperty::class),
            $date,
            new TransformationContext,
        );
    }

    /**
     * Create supported mutable and immutable date values.
     *
     * @return list<DateTimeInterface>
     */
    protected function dates(): array
    {
        $timeZone = new DateTimeZone('UTC');

        return [
            new Carbon('1994-05-19 00:00:00', $timeZone),
            new CarbonImmutable('1994-05-19 00:00:00', $timeZone),
            new DateTime('1994-05-19 00:00:00', $timeZone),
            new DateTimeImmutable('1994-05-19 00:00:00', $timeZone),
            new HypervelCarbon('1994-05-19 00:00:00', $timeZone),
            new HypervelCarbonImmutable('1994-05-19 00:00:00', $timeZone),
        ];
    }
}
