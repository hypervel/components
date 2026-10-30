<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\View;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\EncodedHtmlString;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\View;
use Hypervel\Testbench\Attributes\WithCachedViews;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;

#[WithCachedViews]
class BladeCachedViewsTest extends TestCase
{
    protected string $directory;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ParallelTesting::tempDir('BladeCachedViewsTest');
        (new Filesystem)->deleteDirectory($this->directory);
        (new Filesystem)->ensureDirectoryExists($this->directory);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function testCompiledPathDiffersWhenEchoFormatChanges(): void
    {
        $compiler = Blade::getFacadeRoot();
        $path = $this->directory . '/uses-link.blade.php';

        $default = $compiler->getCompiledPath($path);

        $custom = $compiler->usingEchoFormat(
            'new \Hypervel\Support\EncodedHtmlString(%s)',
            fn (): string => $compiler->getCompiledPath($path)
        );

        $this->assertNotSame($default, $custom);
        $this->assertSame($default, $compiler->getCompiledPath($path));
        $this->assertStringEndsWith('.php', $custom);
    }

    public function testCachedViewIsNotReusedForADifferentEchoFormat(): void
    {
        $compiler = Blade::getFacadeRoot();
        $path = $this->directory . '/echo-format.blade.php';

        file_put_contents($path, '{{ $value }}');

        try {
            $value = '[link](https://evil.example)';

            // Compile with the default echo format, as "view:cache" does.
            $compiler->compile($path);
            $this->assertFileExists($default = $compiler->getCompiledPath($path));

            $rendered = $compiler->usingEchoFormat(
                'new \Hypervel\Support\EncodedHtmlString(%s)',
                function () use ($compiler, $path, $default, $value): string {
                    EncodedHtmlString::encodeUsing(fn (string $string): string => 'SECURED:' . $string);

                    $this->assertNotSame($default, $compiler->getCompiledPath($path));

                    return View::file($path, ['value' => $value])->render();
                }
            );

            $this->assertSame('SECURED:' . $value, $rendered);
        } finally {
            @unlink($path);
        }
    }
}
