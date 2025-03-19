<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\spin;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class SpinnerTest extends TestCase
{
    use RunTestsInCoroutine;

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
