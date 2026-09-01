<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Casts;

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

        $immutable = $cast->cast($this->property('immutable'), '2026-08-30', $state, $context);
        $custom = $cast->cast($this->property('custom'), '2026-08-30', $state, $context);

        $this->assertInstanceOf(DateTimeImmutable::class, $immutable);
        $this->assertSame('2026-08-30', $immutable->format('Y-m-d'));
        $this->assertInstanceOf(CustomDateTimeImmutable::class, $custom);
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

        $this->assertInstanceOf(DateTimeInterface::class, $date);
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
        $this->expectExceptionMessage(DateTimeImmutable::class);
        $this->expectExceptionMessage('Y-m-d');

        (new DateTimeInterfaceCast)->cast(
            $this->property('immutable'),
            'not-a-date',
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
    public DateTimeImmutable $immutable;

    public DateTimeInterface $interface;

    public CustomDateTimeImmutable $custom;

    /** @var list<DateTimeImmutable> */
    public array $dates;

    public string $name;
}

class CustomDateTimeImmutable extends DateTimeImmutable
{
}

abstract class DateCastDataContract implements BaseData
{
}
