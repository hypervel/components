<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

class UnpackedCallbackWriteProvider extends ServiceProvider
{
    /**
     * Write from a callback with unpacked arguments.
     */
    public function register(): void
    {
        $this->configureUsing(static function (Repository $config): void {
            $config->set(...['package.key', 'value']);
        });
    }
}
