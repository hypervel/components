<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Casts;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotCastDate;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Support\Carbon as HypervelCarbon;
use Hypervel\Support\CarbonImmutable as HypervelCarbonImmutable;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class DateTimeInterfaceCastTest extends TestCase
{
    /**
     * Test context formats and exact concrete date targets are preserved.
     */
    public function testCastsConfiguredFormatsToExactConcreteTypes(): void
    {
        [$state, $context] = $this->operation(['Y-m-d', 'Y-m-d H:i:s.uP']);
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
            $date = $cast->cast($this->property($property), '2026-08-30', $state, $context);

            $this->assertSame($type, $date::class);
            $this->assertSame('2026-08-30', $date->format('Y-m-d'));
        }
    }

    /**
     * Test interface declarations use Hypervel's configured date factory.
     */
    public function testCastsDateInterfacesThroughTheDateFactory(): void
    {
        [$state, $context] = $this->operation(['Y-m-d']);

        $date = (new DateTimeInterfaceCast)->cast(
            $this->property('interface'),
            '2026-08-30',
            $state,
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
        [$state, $context] = $this->operation(['Y-m-d H:i:s.uP']);
        $cast = new DateTimeInterfaceCast(
            format: 'Y-m-d H:i:s.uP',
            type: DateTimeImmutable::class,
            setTimeZone: 'America/New_York',
            timeZone: 'UTC',
        );

        $date = $cast->cast(
            $this->property('immutable'),
            '2026-08-30 12:00:00.123456789+00:00',
            $state,
            $context,
        );

        $this->assertSame('2026-08-30 08:00:00.123456-04:00', $date->format('Y-m-d H:i:s.uP'));
    }

    /**
     * Test timezone conversion preserves exact concrete date targets.
     */
    public function testTimezoneConversionPreservesExactConcreteTypes(): void
    {
        [$state, $context] = $this->operation(['Y-m-d H:i:s']);
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
            ))->cast($this->property($property), '2026-08-30 12:00:00', $state, $context);

            $this->assertSame($type, $date::class);
            $this->assertSame('2026-08-30 08:00:00-04:00', $date->format('Y-m-d H:i:sP'));
        }
    }

    /**
     * Test iterable date declarations and non-date declarations.
     */
    public function testCastsIterableDatesAndDeclinesNonDateProperties(): void
    {
        [$state, $context] = $this->operation(['Y-m-d']);
        $cast = new DateTimeInterfaceCast;

        $date = $cast->castIterableItem($this->property('dates'), '2026-08-30', $state, $context);

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame(
            Uncastable::create(),
            $cast->cast($this->property('name'), '2026-08-30', $state, $context),
        );
    }

    /**
     * Test invalid dates name the target and accepted formats.
     */
    public function testThrowsWhenNoDateFormatMatches(): void
    {
        [$state, $context] = $this->operation(['Y-m-d']);

        $this->expectException(CannotCastDate::class);
        $this->expectExceptionMessageIsOrContains(DateTimeImmutable::class);
        $this->expectExceptionMessageMatches('/Y-m-d/');

        (new DateTimeInterfaceCast)->cast(
            $this->property('immutable'),
            'not-a-date',
            $state,
            $context,
        );
    }

    /**
     * Test abstract date targets fail through the ordinary cast exception.
     */
    public function testThrowsForAbstractDateTarget(): void
    {
        [$state, $context] = $this->operation(['Y-m-d']);

        $this->expectException(CannotCastDate::class);
        $this->expectExceptionMessageIsOrContains(AbstractDateTimeImmutable::class);

        (new DateTimeInterfaceCast)->cast(
            $this->property('abstract'),
            '2026-08-30',
            $state,
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
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
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
     * @return array{ConstructionState, CreationContext}
     */
    protected function operation(array $formats): array
    {
        $context = new CreationContext(
            dataClass: DateCastDataContract::class,
            dateFormats: $formats,
        );

        return [ConstructionState::create($context, DateCastDataContract::class), $context];
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

    public string $name;
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
