<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess;

use Hypervel\ServerProcess\Listeners\BootProcessListener;
use Hypervel\ServerProcess\Listeners\LogAfterProcessStoppedListener;
use Hypervel\ServerProcess\Listeners\LogBeforeProcessStartListener;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'listeners' => [
                BootProcessListener::class,
                LogAfterProcessStoppedListener::class,
                LogBeforeProcessStartListener::class,
            ],
        ];
    }
}
