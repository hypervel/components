<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Fixtures\Console;

use Exception;
use Hypervel\Console\Command;

class ThrowExceptionCommand extends Command
{
    protected ?string $signature = 'throw-exception-command';

    public function handle(): void
    {
        throw new Exception('Thrown inside ThrowExceptionCommand');
    }
}
