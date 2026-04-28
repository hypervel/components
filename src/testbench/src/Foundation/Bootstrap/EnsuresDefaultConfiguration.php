<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Foundation\Env;

/**
 * @internal
 */
final class EnsuresDefaultConfiguration
{
    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        if (! $this->includesDefaultConfigurations($app)) {
            return;
        }

        $config = $app->make('config');

        $config->set([
            ...(new Collection([
                'APP_KEY' => ['app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF'],
                'APP_DEBUG' => ['app.debug' => true],
                'DB_CONNECTION' => \defined('TESTBENCH_DUSK') ? ['database.default' => 'testing'] : null,
            ]))->filter()
                ->reject(static fn (?array $configuration, string $key): bool => Env::has($key))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Determine whether default configurations should be included.
     */
    private function includesDefaultConfigurations(Application $app): bool
    {
        return Env::get('TESTBENCH_WITHOUT_DEFAULT_VARIABLES') !== true;
    }
}
