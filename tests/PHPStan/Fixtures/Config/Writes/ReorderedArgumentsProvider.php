<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Config\Writes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Support\ServiceProvider;

class ReorderedArgumentsProvider extends ServiceProvider
{
    /**
     * Write through named arguments that put the key after the value.
     */
    public function register(): void
    {
        $this->configureUsing(static function (Repository $config): void {
            $config->set(value: 'text', key: 'package.callback');
            $config->offsetSet(value: 'text', offset: 'package.offset');
        });
    }

    /**
     * Write through the helper and repositories resolved with reordered arguments.
     */
    protected function writeDirectly(): void
    {
        config(default: null, key: ['package.helper' => 'text']);
        app(parameters: [], abstract: 'config')->set('package.resolved', 'text');
        $this->app->make(parameters: [], abstract: 'config')->set('package.made', 'text');
    }
}
