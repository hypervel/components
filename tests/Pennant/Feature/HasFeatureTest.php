<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Pennant\Concerns\HasFeatures;
use Hypervel\Pennant\Contracts\FeatureScopeable;
use Hypervel\Pennant\Feature;
use Hypervel\Tests\Pennant\TestCase;

class HasFeatureTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('pennant.default', 'array');
    }

    public function testItCanCheckForActiveFeatures(): void
    {
        Feature::define('foo', 'foo-value');
        $class = new class implements FeatureScopeable {
            use HasFeatures;

            public function toFeatureIdentifier(string $driver): mixed
            {
                return 'scope';
            }
        };

        $value = $class->features()->value('foo');

        $this->assertSame('foo-value', $value);
    }
}
