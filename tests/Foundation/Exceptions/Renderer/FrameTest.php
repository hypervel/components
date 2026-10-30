<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Exceptions\Renderer;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Exceptions\Renderer\Frame;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Di\Fixtures\ProxySources;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

class FrameTest extends TestCase
{
    protected string $directory;

    /**
     * Prepare an isolated proxy directory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ParallelTesting::tempDir('FrameTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->directory);
        $files->ensureDirectoryExists($this->directory);
    }

    /**
     * Remove temporary proxy files.
     */
    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('unixFileDataProvider')]
    public function testItNormalizesFilePathOnUnix(array $frameData, string $basePath, string $expected): void
    {
        $exception = new FlattenException;
        $classMap = [];
        $frame = new Frame($exception, $classMap, $frameData, $basePath);

        $this->assertEquals($expected, $frame->file());
    }

    public static function unixFileDataProvider(): iterable
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
        yield 'base directory repeated inside the path' => [
            ['file' => '/app/modules/app/File.php', 'line' => 10],
            '/app',
            'modules/app/File.php',
        ];
    }

    // REMOVED: test_it_normalizes_file_path_on_windows - Swoole doesn't run on Windows
    // REMOVED: windowsFileDataProvider - Swoole doesn't run on Windows

    #[DataProvider('unixIsFromVendorDataProvider')]
    public function testItDeterminesIfFrameIsFromVendorOnUnix(array $frameData, string $basePath, bool $expected): void
    {
        $exception = new FlattenException;
        $classMap = [];
        $frame = new Frame($exception, $classMap, $frameData, $basePath);

        $this->assertEquals($expected, $frame->isFromVendor());
    }

    public static function unixIsFromVendorDataProvider(): iterable
    {
        yield 'vendor file' => [
            ['file' => '/path/to/your-app/vendor/hypervel/framework/src/File.php', 'line' => 10],
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
        yield 'outside base path with matching prefix' => [
            ['file' => '/path/to/your-app-other/file.php', 'line' => 10],
            '/path/to/your-app',
            true,
        ];
        yield 'application directory with vendor prefix' => [
            ['file' => '/path/to/your-app/vendor-tools/file.php', 'line' => 10],
            '/path/to/your-app',
            false,
        ];
        yield 'vendor in filename' => [
            ['file' => '/path/to/your-app/app/vendorfile.php', 'line' => 10],
            '/path/to/your-app',
            false,
        ];
    }

    // REMOVED: test_it_determines_if_frame_is_from_vendor_on_windows - Swoole doesn't run on Windows
    // REMOVED: windowsIsFromVendorDataProvider - Swoole doesn't run on Windows

    public function testGeneratedProxyFramesAreClassifiedByTheirSourceFile(): void
    {
        $proxyFile = $this->directory . '/Frame.proxy.php';
        (new Filesystem)->put($proxyFile, '<?php');

        ProxySources::with([$proxyFile => __FILE__], function () use ($proxyFile): void {
            $frame = new Frame(m::mock(FlattenException::class), [], ['file' => $proxyFile, 'line' => 10], $this->directory);

            $this->assertTrue($frame->isFromVendor());
            $this->assertSame(basename($proxyFile), $frame->file());
        });
    }
}
