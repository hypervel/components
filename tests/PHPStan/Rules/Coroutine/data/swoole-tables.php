<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data;

use Hypervel\Support\ServiceProvider;
use Swoole\Table;

class CounterTable extends Table
{
}

class TableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $table = new Table(1024);

        $this->app->singleton('counters', static function (): Table {
            return new Table(1024);
        });
        $this->app->singleton('limits', static fn (): Table => new Table(1024));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $counters = new CounterTable(1024);
        }
    }
}

class TableRegistrar
{
    public function register(): void
    {
        $table = new Table(1024);
    }
}
