<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap;

use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;

class BootstrapFixtureServiceProvider extends ServiceProvider
{
    /**
     * Register the fixture aspect and class map replacement.
     */
    public function register(): void
    {
        $this->aspects(GreetingAspect::class);
        $this->classMap([ReplacedGreeter::class => __DIR__ . '/replacement/ReplacedGreeter.php']);
    }

    /**
     * Register the fixture macro.
     */
    public function boot(): void
    {
        Str::macro('phpstanBootstrapFixture', static fn (): string => 'booted');
    }
}
