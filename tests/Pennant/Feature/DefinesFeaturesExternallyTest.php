<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Pennant\Contracts\DefinesFeaturesExternally;
use Hypervel\Pennant\Contracts\FeatureScopeable;
use Hypervel\Pennant\Drivers\ArrayDriver;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\Config;
use Hypervel\Tests\Pennant\TestCase;

class DefinesFeaturesExternallyTest extends TestCase
{
    public function testItCanRetrieveAllFeaturesWhenFeaturesAreDefinedExternally(): void
    {
        $driver = new class('external', null, []) extends ArrayDriver implements DefinesFeaturesExternally {
            /**
             * Retrieve the defined features for the given scope.
             *
             * @return list<string>
             */
            public function definedFeaturesForScope(mixed $scope): array
            {
                return [
                    'feature-1',
                    'feature-2',
                ];
            }
        };
        Feature::extend('external', fn () => $driver);
        Config::set('pennant.stores.external', ['driver' => 'external']);

        $features = Feature::driver('external')->all();

        $this->assertSame([
            'feature-1' => false,
            'feature-2' => false,
        ], $features);
    }

    public function testWhenFeaturesAreDefinedExternallyThatScopeIsCorrectlyResolved(): void
    {
        $driver = new class('external', null, []) extends ArrayDriver implements DefinesFeaturesExternally {
            /**
             * Retrieve the defined features for the given scope.
             *
             * @return list<string>
             */
            public function definedFeaturesForScope(mixed $scope): array
            {
                return [
                    $scope,
                ];
            }
        };
        Feature::extend('external', fn () => $driver);
        Config::set('pennant.stores.external', ['driver' => 'external']);

        $features = Feature::driver('external')->for(new class implements FeatureScopeable {
            public function toFeatureIdentifier(string $driver): mixed
            {
                return 'scope-value';
            }
        })->all();

        $this->assertSame([
            'scope-value' => false,
        ], $features);
    }
}
