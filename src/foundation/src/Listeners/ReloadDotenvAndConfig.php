<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hypervel\Config\Repository;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\Support\DotenvManager;

class ReloadDotenvAndConfig
{
    public function __construct(
        protected Application $container,
        protected ConfigMutationTracker $configMutationTracker
    ) {
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

        $this->configMutationTracker->replay($config);
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
     * Rebuild the config repository through the normal foundation bootstrap path.
     */
    protected function rebuildConfigRepository(): Repository
    {
        (new LoadConfiguration)->bootstrap($this->container);

        return $this->container->make(Repository::class);
    }
}
