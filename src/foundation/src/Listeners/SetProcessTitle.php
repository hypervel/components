<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Listeners;

use Hypervel\Contracts\Config\Repository;
use Hyperf\Server\Listener\InitProcessTitleListener;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

class SetProcessTitle extends InitProcessTitleListener
{
    public function __construct(ApplicationContract $container)
    {
        $this->name = $container->get(Repository::class)
            ->get('app.name');
    }
}
