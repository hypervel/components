<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Functions;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\filename_from_classname;

class FilenameFromClassnameTest extends TestCase
{
    #[Test]
    public function itCanResolveClasses(): void
    {
        $this->assertSame(realpath(__FILE__), filename_from_classname(self::class));

        $this->assertFalse(filename_from_classname('Hypervel\Testbench\DefinedValue'));
    }
}
