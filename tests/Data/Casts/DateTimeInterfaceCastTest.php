<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Casts;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonTimeZone;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotCastDate;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Support\Carbon as HypervelCarbon;
use Hypervel\Support\CarbonImmutable as HypervelCarbonImmutable;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

class DateTimeInterfaceCastTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanCastDateTimes(): void
    {
        [$properties, $context] = $this->operation([DATE_ATOM]);
        $cast = new DateTimeInterfaceCast('d-m-Y H:i:s');

        $this->assertEquals(new Carbon('19-05-1994 00:00:00'), $cast->cast($this->property('carbon'), '19-05-1994 00:00:00', $properties, $context));
        $this->assertEquals(new CarbonImmutable('19-05-1994 00:00:00'), $cast->cast($this->property('carbonImmutable'), '19-05-1994 00:00:00', $properties, $context));
        $this->assertEquals(new DateTime('19-05-1994 00:00:00'), $cast->cast($this->property('mutable'), '19-05-1994 00:00:00', $properties, $context));
        $this->assertEquals(new DateTimeImmutable('19-05-1994 00:00:00'), $cast->cast($this->property('immutable'), '19-05-1994 00:00:00', $properties, $context));

        $this->assertEquals(new Carbon('19-05-1994 00:00:00'), $cast->cast($this->property('carbon'), new Carbon('19-05-1994 00:00:00'), $properties, $context));
        $this->assertEquals(new CarbonImmutable('19-05-1994 00:00:00'), $cast->cast($this->property('carbonImmutable'), new CarbonImmutable('19-05-1994 00:00:00'), $properties, $context));
        $this->assertEquals(new DateTime('19-05-1994 00:00:00'), $cast->cast($this->property('mutable'), new DateTime('19-05-1994 00:00:00'), $properties, $context));
        $this->assertEquals(new DateTimeImmutable('19-05-1994 00:00:00'), $cast->cast($this->property('immutable'), new DateTimeImmutable('19-05-1994 00:00:00'), $properties, $context));

        $this->assertEquals(new Carbon('19-05-1994 00:00:00'), $cast->cast($this->property('carbon'), new DateTime('19-05-1994 00:00:00'), $properties, $context));
        $this->assertEquals(new CarbonImmutable('19-05-1994 00:00:00'), $cast->cast($this->property('carbonImmutable'), new DateTimeImmutable('19-05-1994 00:00:00'), $properties, $context));
        $this->assertEquals(new DateTime('19-05-1994 00:00:00'), $cast->cast($this->property('mutable'), new Carbon('19-05-1994 00:00:00'), $properties, $context));
        $this->assertEquals(new DateTimeImmutable('19-05-1994 00:00:00'), $cast->cast($this->property('immutable'), new CarbonImmutable('19-05-1994 00:00:00'), $properties, $context));
    }

    public function testFailsWhenItCannotCastADateIntoTheCorrectFormat(): void
    {
        [$properties, $context] = $this->operation([DATE_ATOM]);

        $this->expectException(CannotCastDate::class);
        $this->expectExceptionMessageIsOrContains(DateTime::class);
        $this->expectExceptionMessageMatches('/d-m-Y H:i:s/');

        (new DateTimeInterfaceCast('d-m-Y H:i:s'))->cast(
            $this->property('mutable'),
            '19-05-1994',
            $properties,
            $context,
        );
    }

    public function testFailsWithOtherTypes(): void
    {
        [$properties, $context] = $this->operation([DATE_ATOM]);

        $this->assertSame(
            Uncastable::create(),
            (new DateTimeInterfaceCast('d-m-Y'))->cast($this->property('int'), '1994-05-16 12:20:00', $properties, $context),
        );
    }

    public function testCanSetAnAlternativeTimezone(): void
    {
        [$properties, $context] = $this->operation([DATE_ATOM]);
        $cast = new DateTimeInterfaceCast('d-m-Y H:i:s', setTimeZone: 'Europe/Brussels');

        foreach (['carbon', 'carbonImmutable'] as $property) {
            $date = $cast->cast($this->property($property), '19-05-1994 00:00:00', $properties, $context);

            $this->assertEquals('1994-05-19 02:00:00', $date->format('Y-m-d H:i:s'));
            $this->assertEquals(CarbonTimeZone::create('Europe/Brussels'), $date->getTimezone());
        }

        foreach (['mutable', 'immutable'] as $property) {
            $date = $cast->cast($this->property($property), '19-05-1994 00:00:00', $properties, $context);

            $this->assertEquals('1994-05-19 02:00:00', $date->format('Y-m-d H:i:s'));
            $this->assertEquals(new DateTimeZone('Europe/Brussels'), $date->getTimezone());
        }
    }

    public function testCanCastDateTimesWithATimezone(): void
    {
        [$properties, $context] = $this->operation([DATE_ATOM]);
        $cast = new DateTimeInterfaceCast('d-m-Y H:i:s', timeZone: 'Europe/Brussels');

        foreach (['carbon', 'carbonImmutable'] as $property) {
            $date = $cast->cast($this->property($property), '19-05-1994 00:00:00', $properties, $context);

            $this->assertEquals('1994-05-19 00:00:00', $date->format('Y-m-d H:i:s'));
            $this->assertEquals(CarbonTimeZone::create('Europe/Brussels'), $date->getTimezone());
        }

        foreach (['mutable', 'immutable'] as $property) {
            $date = $cast->cast($this->property($property), '19-05-1994 00:00:00', $properties, $context);

            $this->assertEquals('1994-05-19 00:00:00', $date->format('Y-m-d H:i:s'));
            $this->assertEquals(new DateTimeZone('Europe/Brussels'), $date->getTimezone());
        }
    }

    public function testCanDefineMultipleDateFormatsToBeUsed(): void
    {
        $data = new class extends Data {
            /**
             * Create the data object with several accepted date formats.
             */
            public function __construct(
                #[WithCast(DateTimeInterfaceCast::class, ['Y-m-d\TH:i:sP', 'Y-m-d H:i:s'])]
                public ?DateTime $date = null
            ) {
            }
        };

        $this->assertSame(['date' => '2022-05-16T14:37:56+00:00'], $data::from(['date' => '2022-05-16T14:37:56+00:00'])->toArray());
        $this->assertSame(['date' => '2022-05-16T17:00:00+00:00'], $data::from(['date' => '2022-05-16 17:00:00'])->toArray());
    }

    public function testCanCastDateTimesWithNanosecondPrecisionByTruncatingNanosecondsToMicroseconds(): void
    {
        [$properties, $context] = $this->operation([DATE_ATOM]);
        $cast = new DateTimeInterfaceCast('Y-m-d\TH:i:s.u\Z');
        $date = '2024-12-02T16:20:15.969827247Z';

        $this->assertEquals(new Carbon($date), $cast->cast($this->property('carbon'), $date, $properties, $context));
        $this->assertEquals(new CarbonImmutable($date), $cast->cast($this->property('carbonImmutable'), $date, $properties, $context));
        $this->assertEquals(new DateTime($date), $cast->cast($this->property('mutable'), $date, $properties, $context));
        $this->assertEquals(new DateTimeImmutable($date), $cast->cast($this->property('immutable'), $date, $properties, $context));
    }

    #[DataProvider('nanosecondOffsetDates')]
    public function testCanCastDateTimesWithNanosecondPrecisionAndATimezoneOffsetByTruncatingNanosecondsToMicroseconds(string $date, string $format): void
    {
        [$properties, $context] = $this->operation([DATE_ATOM]);
        $cast = new DateTimeInterfaceCast($format);

        $this->assertEquals(new Carbon($date), $cast->cast($this->property('carbon'), $date, $properties, $context));
        $this->assertEquals(new CarbonImmutable($date), $cast->cast($this->property('carbonImmutable'), $date, $properties, $context));
        $this->assertEquals(new DateTime($date), $cast->cast($this->property('mutable'), $date, $properties, $context));
        $this->assertEquals(new DateTimeImmutable($date), $cast->cast($this->property('immutable'), $date, $properties, $context));
    }

    /**
     * Provide nanosecond-precision dates with their formats.
     */
    public static function nanosecondOffsetDates(): array
    {
        return [
            'positive offset, 7 digits' => ['2026-03-10T09:55:56.9918655+01:00', 'Y-m-d\TH:i:s.uP'],
            'negative offset, 7 digits' => ['2026-03-10T09:55:56.9918655-05:00', 'Y-m-d\TH:i:s.uP'],
            'zero offset, 9 digits' => ['2026-03-10T09:55:56.969827247+00:00', 'Y-m-d\TH:i:s.uP'],
            'Z suffix, 7 digits' => ['2026-03-10T09:55:56.9918655Z', 'Y-m-d\TH:i:s.u\Z'],
        ];
    }

    /**
     * Test context formats and exact concrete date targets are preserved.
     */
    public function testCastsConfiguredFormatsToExactConcreteTypes(): void
    {
        [$properties, $context] = $this->operation(['Y-m-d', 'Y-m-d H:i:s.uP']);
        $cast = new DateTimeInterfaceCast;

        $types = [
            'mutable' => DateTime::class,
            'immutable' => DateTimeImmutable::class,
            'carbon' => Carbon::class,
            'carbonImmutable' => CarbonImmutable::class,
            'hypervelCarbon' => HypervelCarbon::class,
            'hypervelCarbonImmutable' => HypervelCarbonImmutable::class,
            'custom' => CustomDateTime::class,
            'customImmutable' => CustomDateTimeImmutable::class,
        ];

        foreach ($types as $property => $type) {
            $date = $cast->cast($this->property($property), '2026-08-30', $properties, $context);

            $this->assertSame($type, $date::class);
            $this->assertSame('2026-08-30', $date->format('Y-m-d'));
        }
    }

    /**
     * Test interface declarations use Hypervel's configured date factory.
     */
    public function testCastsDateInterfacesThroughTheDateFactory(): void
    {
        [$properties, $context] = $this->operation(['Y-m-d']);

        $date = (new DateTimeInterfaceCast)->cast(
            $this->property('interface'),
            '2026-08-30',
            $properties,
            $context,
        );

        $this->assertSame(HypervelCarbonImmutable::class, $date::class);
        $this->assertSame('2026-08-30', $date->format('Y-m-d'));
    }

    /**
     * Test source and target timezones and nanosecond truncation.
     */
    public function testAppliesTimezonesAndTruncatesNanoseconds(): void
    {
        [$properties, $context] = $this->operation(['Y-m-d H:i:s.uP']);
        $cast = new DateTimeInterfaceCast(
            format: 'Y-m-d H:i:s.uP',
            type: DateTimeImmutable::class,
            setTimeZone: 'America/New_York',
            timeZone: 'UTC',
        );

        $date = $cast->cast(
            $this->property('immutable'),
            '2026-08-30 12:00:00.123456789+00:00',
            $properties,
            $context,
        );

        $this->assertSame('2026-08-30 08:00:00.123456-04:00', $date->format('Y-m-d H:i:s.uP'));
    }

    /**
     * Test timezone conversion preserves exact concrete date targets.
     */
    public function testTimezoneConversionPreservesExactConcreteTypes(): void
    {
        [$properties, $context] = $this->operation(['Y-m-d H:i:s']);
        $types = [
            'mutable' => DateTime::class,
            'immutable' => DateTimeImmutable::class,
            'carbon' => Carbon::class,
            'carbonImmutable' => CarbonImmutable::class,
            'hypervelCarbon' => HypervelCarbon::class,
            'hypervelCarbonImmutable' => HypervelCarbonImmutable::class,
            'custom' => CustomDateTime::class,
            'customImmutable' => CustomDateTimeImmutable::class,
        ];

        foreach ($types as $property => $type) {
            $date = (new DateTimeInterfaceCast(
                format: 'Y-m-d H:i:s',
                setTimeZone: 'America/New_York',
                timeZone: 'UTC',
            ))->cast($this->property($property), '2026-08-30 12:00:00', $properties, $context);

            $this->assertSame($type, $date::class);
            $this->assertSame('2026-08-30 08:00:00-04:00', $date->format('Y-m-d H:i:sP'));
        }
    }

    public function testCastsIterableDates(): void
    {
        [$properties, $context] = $this->operation(['Y-m-d']);

        $date = (new DateTimeInterfaceCast)->castIterableItem($this->property('dates'), '2026-08-30', $properties, $context);

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('2026-08-30', $date->format('Y-m-d'));
    }

    /**
     * Test abstract date targets fail through the ordinary cast exception.
     */
    public function testThrowsForAbstractDateTarget(): void
    {
        [$properties, $context] = $this->operation(['Y-m-d']);

        $this->expectException(CannotCastDate::class);
        $this->expectExceptionMessageIsOrContains(AbstractDateTimeImmutable::class);

        (new DateTimeInterfaceCast)->cast(
            $this->property('abstract'),
            '2026-08-30',
            $properties,
            $context,
        );
    }

    /**
     * Build one property definition.
     */
    protected function property(string $name): DataProperty
    {
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader);
        $reflectionClass = new ReflectionClass(DateCastDataFixture::class);
        $reflectionProperty = $reflectionClass->getProperty($name);

        return (new DataPropertyFactory(
            $typeFactory,
            $config,
            new NameMapperResolver(new Container),
        ))->build(
            $reflectionProperty,
            $reflectionClass,
            classDefinedDataIterableAnnotations: (new DataIterableAnnotationReader)->getForProperty(
                $reflectionProperty,
            ),
        );
    }

    /**
     * Create one date cast operation.
     *
     * @param non-empty-list<string> $formats
     * @return array{array<string, mixed>, CreationContext}
     */
    protected function operation(array $formats): array
    {
        return [[], new CreationContext(
            dataClass: DateCastDataContract::class,
            dateFormats: $formats,
        )];
    }
}

class DateCastDataFixture
{
    public DateTime $mutable;

    public DateTimeImmutable $immutable;

    public DateTimeInterface $interface;

    public Carbon $carbon;

    public CarbonImmutable $carbonImmutable;

    public HypervelCarbon $hypervelCarbon;

    public HypervelCarbonImmutable $hypervelCarbonImmutable;

    public CustomDateTime $custom;

    public CustomDateTimeImmutable $customImmutable;

    public AbstractDateTimeImmutable $abstract;

    /** @var list<DateTimeImmutable> */
    public array $dates;

    public int $int;
}

class CustomDateTimeImmutable extends DateTimeImmutable
{
}

class CustomDateTime extends DateTime
{
}

abstract class AbstractDateTimeImmutable extends DateTimeImmutable
{
}

abstract class DateCastDataContract implements BaseData
{
}
