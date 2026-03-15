<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Exceptions\Renderer;

use Hypervel\Foundation\Exceptions\Renderer\Frame;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * @internal
 * @coversNothing
 */
class FrameTest extends TestCase
{
    #[DataProvider('unixFileDataProvider')]
    public function testItNormalizesFilePathOnUnix($frameData, $basePath, $expected)
    {
        $exception = m::mock(FlattenException::class);
        $classMap = [];
        $frame = new Frame($exception, $classMap, $frameData, $basePath);

        $this->assertEquals($expected, $frame->file());
    }

    public static function unixFileDataProvider()
    {
        yield 'internal function' => [
            ['line' => 10],
            '/path/to/your-app',
            '[internal function]',
        ];
        yield 'unknown file' => [
            ['file' => 123, 'line' => 10],
            '/path/to/your-app',
            '[unknown file]',
        ];
        yield 'file with base path' => [
            ['file' => '/path/to/your-app/app/Http/Controllers/UserController.php', 'line' => 10],
            '/path/to/your-app',
            'app/Http/Controllers/UserController.php',
        ];
        yield 'file without base path' => [
            ['file' => '/other/path/app/Http/Controllers/UserController.php', 'line' => 10],
            '/path/to/your-app',
            '/other/path/app/Http/Controllers/UserController.php',
        ];
    }

    // REMOVED: test_it_normalizes_file_path_on_windows - Swoole doesn't run on Windows
    // REMOVED: windowsFileDataProvider - Swoole doesn't run on Windows

    #[DataProvider('unixIsFromVendorDataProvider')]
    public function testItDeterminesIfFrameIsFromVendorOnUnix($frameData, $basePath, $expected)
    {
        $exception = m::mock(FlattenException::class);
        $classMap = [];
        $frame = new Frame($exception, $classMap, $frameData, $basePath);

        $this->assertEquals($expected, $frame->isFromVendor());
    }

    public static function unixIsFromVendorDataProvider()
    {
        yield 'vendor file' => [
            ['file' => '/path/to/your-app/vendor/laravel/framework/src/File.php', 'line' => 10],
            '/path/to/your-app',
            true,
        ];
        yield 'app file' => [
            ['file' => '/path/to/your-app/app/Models/User.php', 'line' => 10],
            '/path/to/your-app',
            false,
        ];
        yield 'outside base path' => [
            ['file' => '/other/path/file.php', 'line' => 10],
            '/path/to/your-app',
            true,
        ];
        yield 'vendor in filename' => [
            ['file' => '/path/to/your-app/app/vendorfile.php', 'line' => 10],
            '/path/to/your-app',
            false,
        ];
    }

    // REMOVED: test_it_determines_if_frame_is_from_vendor_on_windows - Swoole doesn't run on Windows
    // REMOVED: windowsIsFromVendorDataProvider - Swoole doesn't run on Windows
}
