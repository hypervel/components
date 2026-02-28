<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Server\Listener\InitProcessTitleListener;

class SetProcessTitle extends InitProcessTitleListener
{
    public function __construct(ApplicationContract $container)
    {
        $this->name = $container->make('config')
            ->get('app.name');
    }
}
