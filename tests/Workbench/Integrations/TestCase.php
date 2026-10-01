<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations;

use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase as BaseTestCase;
use Hypervel\Testbench\Workbench\Workbench;
use Override;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
#[WithConfig('database.default', 'testing')]
#[WithMigration]
abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    /**
     * Get the cached Workbench configuration with authentication enabled.
     *
     * The shared testbench.yaml leaves Workbench authentication disabled for
     * every other Testbench test.
     */
    public static function cachedConfigurationForWorkbench(): ConfigContract
    {
        $config = Workbench::configuration();

        return new Config([
            ...$config->getAttributes(),
            'workbench' => [...$config['workbench'], 'auth' => true],
        ]);
    }

    /**
     * Render pages without the built Vite assets.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
