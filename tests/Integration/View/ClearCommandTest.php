<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\View;

use Hypervel\Foundation\Console\ViewClearCommand;
use Hypervel\Support\Facades\File;
use Hypervel\Testbench\TestCase;

class ClearCommandTest extends TestCase
{
    public function testClearViewCommandShouldRemoveParallelTestDirectories(): void
    {
        $globResult = [
            '/views/cache/path/filehash123.php',
            '/views/cache/path/test_33',
        ];
        File::expects('glob')->andReturn($globResult);

        File::expects('isDirectory')->with($globResult[0])->andReturnFalse();
        File::expects('isDirectory')->with($globResult[1])->andReturnTrue();
        File::expects('delete')->with($globResult[0])->andReturnTrue();
        File::expects('deleteDirectory')->with($globResult[1])->andReturnTrue();

        $this->artisan(ViewClearCommand::class);
    }
}
