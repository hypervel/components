<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hypervel\Config\Repository;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Support\DotenvManager;

class ReloadDotenvAndConfig
{
    protected static array $modifiedItems = [];

    protected static bool $stopCallback = false;

    public function __construct(protected Application $container)
    {
        $this->setConfigCallback(
            $this->container->make(Repository::class)
        );
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
        $config = $this->rebuildConfigRepository();

        $this->setConfigCallback($config);
        $this->replayModifiedItems($config);
    }

    protected function reloadDotenv(): void
    {
        if (! file_exists($this->container->environmentFilePath())) {
            return;
        }

        DotenvManager::reload(
            [$this->container->environmentPath()],
            $this->container->environmentFile(),
        );
    }

    /**
     * Track runtime config mutations on the active repository instance.
     */
    protected function setConfigCallback(Repository $config): void
    {
        $config->afterSettingCallback(function (array $values): void {
            if (static::$stopCallback) {
                return;
            }

            static::$modifiedItems = array_replace(
                static::$modifiedItems,
                $values
            );
        });
    }

    /**
     * Rebuild the config repository through the normal foundation bootstrap path.
     */
    protected function rebuildConfigRepository(): Repository
    {
        (new LoadConfiguration)->bootstrap($this->container);

        return $this->container->make(Repository::class);
    }

    /**
     * Reapply runtime config mutations onto a freshly rebuilt repository.
     */
    protected function replayModifiedItems(Repository $config): void
    {
        if (static::$modifiedItems === []) {
            return;
        }

        static::$stopCallback = true;

        try {
            $config->set(static::$modifiedItems);
        } finally {
            static::$stopCallback = false;
        }
    }

    /**
     * Flush the listener's static mutation tracking state.
     */
    public static function flushState(): void
    {
        static::$modifiedItems = [];
        static::$stopCallback = false;
    }
}
