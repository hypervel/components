<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Functions;

use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\default_skeleton_path;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\transform_realpath_to_relative;

/**
 * @internal
 * @coversNothing
 */
class TransformRealpathToRelativeTest extends TestCase
{
    #[Test]
    public function itCanUseTransformRealpathToRelative()
    {
        $this->assertSame('Testbench.php', transform_realpath_to_relative('Testbench.php'));

        $this->assertSame(
            join_paths('.', 'src', 'testbench', 'src', 'TestCase.php'),
            transform_realpath_to_relative(package_path('src', 'testbench', 'src', 'TestCase.php'))
        );

        $this->assertSame(
            join_paths('@hypervel', 'composer.json'),
            transform_realpath_to_relative(default_skeleton_path('composer.json'))
        );

        $this->assertSame(
            join_paths('@workbench', 'app', 'Providers', 'WorkbenchServiceProvider.php'),
            transform_realpath_to_relative(package_path('src', 'testbench', 'workbench', 'app', 'Providers', 'WorkbenchServiceProvider.php'))
        );
    }

    #[Test]
    public function itCanUseTransformRealpathToRelativeUsingCustomWorkingPath()
    {
        $this->assertSame(
            join_paths('@tests', 'Testbench', 'Functions', 'TransformRealpathToRelativeTest.php'),
            transform_realpath_to_relative(__FILE__, package_path('tests'), '@tests')
        );
    }
}
