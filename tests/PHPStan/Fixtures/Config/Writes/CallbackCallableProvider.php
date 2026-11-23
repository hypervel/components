<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

class CallbackCallableProvider extends ServiceProvider
{
    /**
     * Write from a callback through a callable created from the repository.
     */
    public function register(): void
    {
        $this->configureUsing(static function (Repository $config): void {
            $set = $config->set(...);
            $set('package.key', 'value');
        });
    }
}
