<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Server\Listener\InitProcessTitleListener;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;

class SetProcessTitle extends InitProcessTitleListener
{
    public function __construct(ApplicationContract $container)
    {
        $this->name = $container->get(ConfigInterface::class)
            ->get('app.name');
    }
}
