<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Support\DotenvManager;

class ReloadDotenvAndConfig
{
    protected static array $modifiedItems = [];

    protected static bool $stopCallback = false;

    public function __construct(protected Application $container)
    {
        $this->setConfigCallback();

        $container->afterResolving('config', function (Repository $config) {
            if (static::$stopCallback) {
                return;
            }

            static::$stopCallback = true;
            foreach (static::$modifiedItems as $key => $value) {
                $config->set($key, $value);
            }
            static::$stopCallback = false;
        });
    }

    /**
     * Reload dotenv and config before a worker starts.
     */
    public function handle(BeforeWorkerStart $event): void
    {
        $this->reloadDotenv();
        $this->reloadConfig();
    }

    protected function reloadConfig(): void
    {
        $this->container->forgetInstance('config');
    }

    protected function reloadDotenv(): void
    {
        $basePath = $this->container->basePath();
        if (! file_exists($basePath . DIRECTORY_SEPARATOR . '.env')) {
            return;
        }

        DotenvManager::reload([$basePath]);
    }

    protected function setConfigCallback(): void
    {
        $this->container->make('config')
            ->afterSettingCallback(function (array $values) {
                static::$modifiedItems = array_replace(
                    static::$modifiedItems,
                    $values
                );
            });
    }
}
