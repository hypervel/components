<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Console;

use App\Console\Commands\BarCommand;

use function PHPStan\Testing\assertType;

class WithArgumentCommand extends LookupCommand
{
    protected ?string $signature = 'lookup:with {argument} {--option=}';

    /**
     * Get the value of a command option, accepting "alias" for "option".
     */
    public function option(?string $key = null): mixed
    {
        return parent::option($key === 'alias' ? 'option' : $key);
    }

    /**
     * Read another command's argument and an option name only the override accepts.
     */
    public function lookups(BarCommand $bar): void
    {
        assertType('array<int, string>', $bar->argument('argumentArray'));
        assertType('mixed', $this->option('alias'));
    }
}
