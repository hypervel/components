<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Tests\TestCase;

class FilesystemStaticStateTest extends TestCase
{
    public function testFilesystemFlushStateClearsMacros()
    {
        Filesystem::macro('testMacro', function () {
            return 'test';
        });

        $this->assertTrue(Filesystem::hasMacro('testMacro'));

        Filesystem::flushState();

        $this->assertFalse(Filesystem::hasMacro('testMacro'));
    }

    public function testFilesystemAdapterFlushStateClearsMacros()
    {
        FilesystemAdapter::macro('testMacro', function () {
            return 'test';
        });

        $this->assertTrue(FilesystemAdapter::hasMacro('testMacro'));

        FilesystemAdapter::flushState();

        $this->assertFalse(FilesystemAdapter::hasMacro('testMacro'));
    }
}
