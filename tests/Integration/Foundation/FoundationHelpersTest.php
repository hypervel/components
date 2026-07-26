<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Exception;
use Faker\Provider\en_AU\Address as AustralianAddress;
use Faker\Provider\en_US\Address as AmericanAddress;
use Hypervel\Testbench\TestCase;

class FoundationHelpersTest extends TestCase
{
    public function testRescue()
    {
        $this->assertEquals(
            'rescued!',
            rescue(function () {
                throw new Exception;
            }, 'rescued!')
        );

        $this->assertEquals(
            'rescued!',
            rescue(function () {
                throw new Exception;
            }, function () {
                return 'rescued!';
            })
        );

        $this->assertEquals(
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

        $this->assertEquals(
            'rescued!',
            rescue(function () use ($testClass) {
                $testClass->test([]);
            }, 'rescued!')
        );
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
