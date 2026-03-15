<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\BackupStaticProperties;

use function Hypervel\Prompts\spin;

/**
 * @internal
 * @coversNothing
 */
#[BackupStaticProperties(true)]
class SpinnerTest extends TestCase
{
    public function testSpinner()
    {
        Prompt::fake();

        $result = spin(function () {
            return 'done';
        }, 'Running...');

        $this->assertSame('done', $result);

        Prompt::assertOutputContains('Running...');
    }
}
