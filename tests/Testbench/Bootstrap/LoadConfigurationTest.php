<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Bootstrap;

use Hypervel\Contracts\Config\Repository as RepositoryContract;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Bootstrap\LoadConfiguration;
use Hypervel\Tests\TestCase;

class LoadConfigurationTest extends TestCase
{
    public function testKeepsNestedFilesBelowFrameworkOnlyConfiguration(): void
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useConfigPath(__DIR__ . '/../Fixtures/nested-config');

        (new LoadConfiguration)->bootstrap($app);

        $config = $app->make('config');

        $this->assertSame('stripe-key', $config->string('services.stripe.key'));
        $this->assertIsArray($config->get('services.postmark'));
    }

    public function testAppliesBaseEntriesAnOverriddenFileLoaderAddsOrChanges(): void
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useConfigPath(__DIR__ . '/../Fixtures/nested-config');

        (new ResidualBaseLoadConfiguration)->bootstrap($app);

        $config = $app->make('config');

        $this->assertSame(['enabled' => true], $config->get('additional'));
        $this->assertSame('override', $config->string('session.driver'));
        $this->assertSame('stripe-key', $config->string('services.stripe.key'));
    }
}

class ResidualBaseLoadConfiguration extends LoadConfiguration
{
    protected function loadConfigurationFile(RepositoryContract $repository, string $name, string $path, array $base): array
    {
        $base = parent::loadConfigurationFile($repository, $name, $path, $base);

        $base['additional'] = ['enabled' => true];
        $base['session']['driver'] = 'override';

        return $base;
    }
}
