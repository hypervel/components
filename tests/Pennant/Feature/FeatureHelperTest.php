<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Pennant\Feature;
use Hypervel\Tests\Pennant\TestCase;

class FeatureHelperTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('pennant.default', 'array');
    }

    public function testItReturnsFeatureManager(): void
    {
        $this->assertNotNull(feature());
        $this->assertSame(Feature::getFacadeRoot(), feature());
    }

    public function testItReturnsTheFeatureValue(): void
    {
        Feature::activate('foo', 'bar');

        $this->assertSame('bar', feature('foo'));
    }

    public function testItConditionallyExecutesCodeBlocks(): void
    {
        Feature::activate('foo');
        $inactive = $active = null;

        feature('foo', function () use (&$active) {
            $active = true;
        }, function () use (&$inactive) {
            $inactive = true;
        });

        $this->assertTrue($active);
        $this->assertNull($inactive);

        Feature::deactivate('foo');
        $inactive = $active = null;

        feature('foo', function () use (&$active) {
            $active = true;
        }, function () use (&$inactive) {
            $inactive = true;
        });

        $this->assertNull($active);
        $this->assertTrue($inactive);
    }
}
