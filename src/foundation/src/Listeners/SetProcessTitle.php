<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hypervel\Server\Listener\InitProcessTitleListener;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

class SetProcessTitle extends InitProcessTitleListener
{
    public function __construct(ApplicationContract $container)
    {
        $this->name = $container->make('config')
            ->get('app.name');
    }
}
