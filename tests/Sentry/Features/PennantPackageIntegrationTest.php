<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Pennant\Feature;
use Hypervel\Tests\Sentry\TestCase;
use Sentry\Event;

class PennantPackageIntegrationTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        tap($app->make('config'), static function (Repository $config) {
            // Force Pennant to use the array driver instead of the database which is the default
            $config->set('pennant.default', 'array');
            $config->set('pennant.stores.array', ['driver' => 'array']);
        });
    }

    public function testPennantFeatureIsRecorded(): void
    {
        Feature::define('new-dashboard', static function () {
            return true;
        });

        Feature::active('new-dashboard');

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'new-dashboard',
                'result' => true,
            ],
        ], $flags);
    }

    public function testPennantFeatureWithLeadingNamespaceSeparatorIsRecordedWithNormalizedName(): void
    {
        Feature::define('\App\Features\NewApi', static function () {
            return true;
        });

        Feature::active('\App\Features\NewApi');

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'App.Features.NewApi',
                'result' => true,
            ],
        ], $flags);
    }

    public function testPennantClassBasedFeatureIsRecordedWithNormalizedName(): void
    {
        Feature::active(PennantClassBasedFeature::class);

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'Hypervel.Tests.Sentry.Features.PennantClassBasedFeature',
                'result' => true,
            ],
        ], $flags);
    }

    public function testPennantRichFeatureIsRecordedAsActive(): void
    {
        Feature::define('dashboard-version', static function () {
            return 'dark';
        });

        Feature::value('dashboard-version');

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'dashboard-version',
                'result' => true,
            ],
        ], $flags);
    }

    public function testPennantRichFeatureIsRecordedAsInactive(): void
    {
        Feature::define('dashboard-version', static function () {
            return false;
        });

        Feature::value('dashboard-version');

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'dashboard-version',
                'result' => false,
            ],
        ], $flags);
    }
}

class PennantClassBasedFeature
{
    public function resolve(): bool
    {
        return true;
    }
}
