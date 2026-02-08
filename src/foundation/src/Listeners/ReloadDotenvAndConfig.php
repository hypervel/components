<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\DotenvManager;

class ReloadDotenvAndConfig implements ListenerInterface
{
    protected static array $modifiedItems = [];

    protected static bool $stopCallback = false;

    public function __construct(protected ApplicationContract $container)
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

    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        $this->reloadDotenv();
        $this->reloadConfig();
    }

    protected function reloadConfig(): void
    {
        $this->container->unbind('config');
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
        $this->container->get('config')
            ->afterSettingCallback(function (array $values) {
                static::$modifiedItems = array_replace(
                    static::$modifiedItems,
                    $values
                );
            });
    }
}
