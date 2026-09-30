<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Exception;
use Faker\Provider\en_AU\Address as AustralianAddress;
use Faker\Provider\en_US\Address as AmericanAddress;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Testbench\TestCase;
use Mockery\MockInterface;
use Psr\Log\LogLevel;
use Swoole\Coroutine\CanceledException;
use Throwable;

class FoundationHelpersTest extends TestCase
{
    public function testReportHelpersForwardContextAndLevel(): void
    {
        $handler = new FakeHandler;
        $this->app->instance(ExceptionHandler::class, $handler);

        report($first = new Exception('First'), ['id' => 1], LogLevel::WARNING);
        report_if(true, $second = new Exception('Second'), ['id' => 2], LogLevel::NOTICE);
        report_unless(false, $third = new Exception('Third'), ['id' => 3], LogLevel::INFO);

        $this->assertSame([$first, $second, $third], $handler->reported);
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $handler->contexts);
        $this->assertSame([LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO], $handler->levels);
    }

    public function testReportHelpersOnlyForwardLevelWhenGiven(): void
    {
        $exception = new Exception('Test');

        $this->mock(ExceptionHandler::class, function (MockInterface $mock) use ($exception): void {
            $mock->expects('report')->times(3)->withArgs(fn (mixed ...$arguments): bool => $arguments === [$exception, ['id' => 1]]);
        });

        report($exception, ['id' => 1]);
        report_if(true, $exception, ['id' => 1]);
        report_unless(false, $exception, ['id' => 1]);
    }

    public function testRescue(): void
    {
        $this->assertSame(
            'rescued!',
            rescue(function () {
                throw new Exception;
            }, 'rescued!')
        );

        $this->assertSame(
            'rescued!',
            rescue(function () {
                throw new Exception;
            }, function () {
                return 'rescued!';
            })
        );

        $this->assertSame(
            'no need to rescue',
            rescue(function () {
                return 'no need to rescue';
            }, 'rescued!')
        );

        $testClass = new class {
            public function test(int $a)
            {
                return $a;
            }
        };

        $this->assertSame(
            'rescued!',
            rescue(function () use ($testClass) {
                $testClass->test([]);
            }, 'rescued!')
        );
    }

    public function testRescuePreservesCancellation(): void
    {
        $cancellation = new CanceledException('canceled');
        $reported = false;
        $rescued = false;

        try {
            rescue(
                fn () => throw $cancellation,
                function () use (&$rescued): void {
                    $rescued = true;
                },
                function () use (&$reported): bool {
                    $reported = true;

                    return true;
                },
            );

            $this->fail('The cancellation was not preserved.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertFalse($reported);
        $this->assertFalse($rescued);
    }

    // REMOVED: testMixReportsExceptionWhenAssetIsMissingFromManifest - Mix deleted from Hypervel
    // REMOVED: testMixSilentlyFailsWhenAssetIsMissingFromManifestWhenNotInDebugMode - Mix deleted from Hypervel
    // REMOVED: testMixThrowsExceptionWhenAssetIsMissingFromManifestWhenInDebugMode - Mix deleted from Hypervel
    // REMOVED: testMixOnlyThrowsAndReportsOneExceptionWhenAssetIsMissingFromManifestWhenInDebugMode - Mix deleted from Hypervel

    public function testFakeReturnsSameInstance()
    {
        $this->assertSame(fake(), fake());
        $this->assertSame(fake(), fake('en_US'));
        $this->assertSame(fake('en_AU'), fake('en_AU'));
        $this->assertNotSame(fake('en_US'), fake('en_AU'));
    }

    public function testFakeUsesLocale()
    {
        // Process-global RNG state is not coroutine-isolated, so assert locale ownership instead of an exact draw.
        $this->assertContains(
            AmericanAddress::class,
            array_map(static fn (object $provider): string => $provider::class, fake()->getProviders()),
        );
        $this->assertContains(fake('de_DE')->state(), [
            'Baden-Württemberg', 'Bayern', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hessen', 'Mecklenburg-Vorpommern', 'Niedersachsen', 'Nordrhein-Westfalen', 'Rheinland-Pfalz', 'Saarland', 'Sachsen', 'Sachsen-Anhalt', 'Schleswig-Holstein', 'Thüringen',
        ]);
        $this->assertContains(fake('fr_FR')->region(), [
            'Auvergne-Rhône-Alpes', 'Bourgogne-Franche-Comté', 'Bretagne', 'Centre-Val de Loire', 'Corse', 'Grand Est', 'Hauts-de-France',
            'Île-de-France', 'Normandie', 'Nouvelle-Aquitaine', 'Occitanie', 'Pays de la Loire', "Provence-Alpes-Côte d'Azur",
            'Guadeloupe', 'Martinique', 'Guyane', 'La Réunion', 'Mayotte',
        ]);

        config(['app.faker_locale' => 'en_AU']);
        $faker = fake();

        $this->assertContains(
            AustralianAddress::class,
            array_map(static fn (object $provider): string => $provider::class, $faker->getProviders()),
        );
        $this->assertContains($faker->state(), [
            'Australian Capital Territory', 'New South Wales', 'Northern Territory', 'Queensland',
            'South Australia', 'Tasmania', 'Victoria', 'Western Australia',
        ]);
    }
}

class FakeHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    /** @var list<array<array-key, mixed>> */
    public array $contexts = [];

    /** @var list<null|string> */
    public array $levels = [];

    /**
     * Record the reported exception, context and level.
     */
    public function report(Throwable $exception, array $context = [], ?string $level = null): void
    {
        $this->reported[] = $exception;
        $this->contexts[] = $context;
        $this->levels[] = $level;
    }
}
