<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Testbench\Attributes\UsesVendor;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\remote;

/**
 * @internal
 * @coversNothing
 */
#[UsesVendor]
class ArtisanTest extends TestCase
{
    #[Test]
    public function itCanGenerateTheSameOutput()
    {
        $remote = remote('--version --no-ansi')->mustRun();

        $artisan = (new Process(
            command: [php_binary(), 'artisan', '--version', '--no-ansi'],
            cwd: BASE_PATH,
            env: ['TESTBENCH_WORKING_PATH' => package_path()],
        ))->mustRun();

        $this->assertSame(json_decode($artisan->getOutput(), true), json_decode($remote->getOutput(), true));
    }
}
