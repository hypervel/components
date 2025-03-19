<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hyperf\Support\DotenvManager;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;

class ReloadDotenvAndConfig implements ListenerInterface
{
    protected static array $modifiedItems = [];

    protected static bool $stopCallback = false;

    public function __construct(protected ApplicationContract $container)
    {
        $this->setConfigCallback();

        $container->afterResolving(ConfigInterface::class, function (ConfigInterface $config) {
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
        $this->container->unbind(ConfigInterface::class);
    }

    protected function reloadDotenv(): void
    {
        if (! file_exists($basePath = $this->container->basePath())) {
            return;
        }

        DotenvManager::reload([$basePath]);
    }

    protected function setConfigCallback(): void
    {
        $this->container->get(ConfigInterface::class)
            ->afterSettingCallback(function (array $values) {
                static::$modifiedItems = array_merge(
                    static::$modifiedItems,
                    $values
                );
            });
    }
}
