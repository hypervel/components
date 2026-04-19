<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\remote;

#[RequiresOperatingSystem('Linux|Darwin')]
class AboutCommandTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    #[Test]
    public function itIncludesTestbenchInformationInTheAboutCommandOutput()
    {
        $this->withoutSqliteDatabase(function (): void {
            $process = remote('about --json');
            $process->mustRun();

            $output = json_decode($process->getOutput(), true);

            $this->assertIsArray($output);
            $this->assertArrayHasKey('testbench', $output);
            $this->assertSame(BASE_PATH, $output['testbench']['skeleton_path']);
        });
    }
}
