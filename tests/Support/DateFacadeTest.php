<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable as BaseCarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Factory;
use Carbon\FactoryImmutable;
use DateTime;
use DateTimeInterface;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\DateFactory;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class DateFacadeTest extends TestCase
{
    protected static function assertBetweenStartAndNow(int $start, int $actual): void
    {
        static::assertThat(
            $actual,
            static::logicalAnd(
                static::greaterThanOrEqual($start),
                static::lessThanOrEqual(CarbonImmutable::now()->getTimestamp())
            )
        );
    }

    public function testDefaultDateRoutesReturnHypervelCarbonImmutable(): void
    {
        $start = CarbonImmutable::now()->getTimestamp();

        $this->assertSame(CarbonImmutable::class, Date::now()::class);
        $this->assertSame(CarbonImmutable::class, Date::parse('2026-07-22')::class);
        $this->assertSame(CarbonImmutable::class, Date::today()::class);
        $this->assertSame(CarbonImmutable::class, now()::class);
        $this->assertSame(CarbonImmutable::class, today()::class);
        $this->assertBetweenStartAndNow($start, Date::now()->getTimestamp());
    }

    public function testMutableClassIsAnExplicitOptOutAcrossDateRoutes(): void
    {
        DateFactory::use(Carbon::class);

        $this->assertSame(Carbon::class, Date::now()::class);
        $this->assertSame(Carbon::class, Date::parse('2026-07-22')::class);
        $this->assertSame(Carbon::class, Date::today()::class);
        $this->assertSame(Carbon::class, now()::class);
        $this->assertSame(Carbon::class, today()::class);
    }

    public function testUseClosureCanTransformTheGeneratedCarbonValue(): void
    {
        DateFactory::use(function (CarbonImmutable $date): Carbon {
            return $date->toMutable()->addDay();
        });

        $date = Date::parse('2026-07-22 12:34:56');

        $this->assertSame(Carbon::class, $date::class);
        $this->assertSame('2026-07-23 12:34:56', $date->toDateTimeString());
    }

    public function testUseSupportsInvokableObjectsAndCallableStrings(): void
    {
        DateFactory::use(new DateFacadeInvokableHandler);
        $this->assertSame('2026-07-23', Date::parse('2026-07-22')->toDateString());

        DateFactory::use(DateFacadeCallableHandler::class . '::handle');
        $this->assertSame('2026-07-24', Date::parse('2026-07-22')->toDateString());
    }

    public function testConcreteCarbonClassHandlersReturnTheExactConfiguredClass(): void
    {
        foreach ([
            Carbon::class,
            CarbonImmutable::class,
            BaseCarbon::class,
            BaseCarbonImmutable::class,
            DateFacadeCustomCarbon::class,
        ] as $dateClass) {
            DateFactory::use($dateClass);

            $this->assertSame($dateClass, Date::now()::class);
        }
    }

    public function testMutableAndImmutableFactoriesReturnTheirConfiguredClasses(): void
    {
        DateFactory::use(new Factory(['locale' => 'fr'], Carbon::class));
        $mutable = Date::now();

        $this->assertSame(Carbon::class, $mutable::class);
        $this->assertSame('fr', $mutable->locale());

        DateFactory::use(new FactoryImmutable(['locale' => 'de'], CarbonImmutable::class));
        $immutable = Date::now();

        $this->assertSame(CarbonImmutable::class, $immutable::class);
        $this->assertSame('de', $immutable->locale());
    }

    public function testFactoryTimezoneConfigurationReturnsAClonedFactory(): void
    {
        $factory = new Factory;
        DateFactory::use($factory);

        $configured = Date::withTimeZone('America/Toronto');

        $this->assertSame(Factory::class, $configured::class);
        $this->assertNotSame($factory, $configured);
        $this->assertSame('America/Toronto', $configured->now()->timezoneName);
    }

    public function testFlushStateRestoresDefaultDateFactory(): void
    {
        DateFactory::use(Carbon::class);
        $this->assertSame(Carbon::class, Date::now()::class);

        DateFactory::flushState();

        $this->assertSame(CarbonImmutable::class, Date::now()::class);
    }

    #[DataProvider('invalidClassHandlerProvider')]
    public function testUseAndUseClassRejectInvalidClassHandlers(string $dateClass): void
    {
        foreach (['use', 'useClass'] as $method) {
            try {
                DateFactory::{$method}($dateClass);
                $this->fail("{$method} accepted invalid date class {$dateClass}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function invalidClassHandlerProvider(): array
    {
        return [
            'native DateTime' => [DateTime::class],
            'Carbon interface' => [CarbonInterface::class],
            'abstract Carbon class' => [DateFacadeAbstractCarbon::class],
            'non-Carbon wrapper' => [DateFacadeNonCarbonWrapper::class],
            'non-Carbon class' => [stdClass::class],
        ];
    }

    public function testUseRejectsInvalidScalarHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateFactory::use(42);
    }

    public function testMacro(): void
    {
        Date::macro('returnNonDate', function (): string {
            return 'string';
        });

        $this->assertSame('string', Date::returnNonDate());
    }
}

class DateFacadeCustomCarbon extends CarbonImmutable
{
}

abstract class DateFacadeAbstractCarbon extends CarbonImmutable
{
}

class DateFacadeNonCarbonWrapper
{
    public static function instance(DateTimeInterface $date): static
    {
        return new static;
    }
}

class DateFacadeInvokableHandler
{
    public function __invoke(CarbonImmutable $date): CarbonInterface
    {
        return $date->addDay();
    }
}

class DateFacadeCallableHandler
{
    public static function handle(CarbonImmutable $date): CarbonInterface
    {
        return $date->addDays(2);
    }
}
