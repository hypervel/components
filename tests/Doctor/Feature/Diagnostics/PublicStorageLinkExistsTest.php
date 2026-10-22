<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Doctor\Diagnostics\PublicStorageLinkExists;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class PublicStorageLinkExistsTest extends TestCase
{
    private string $basePath;

    /**
     * Create an isolated application directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorPublicStorage');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath, 0775, true);
        $this->app->setBasePath($this->basePath);
    }

    /**
     * Remove temporary links and files.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testReportsAMissingPublicStorageLinkAndCanFixIt(): void
    {
        $basePath = $this->basePath;
        mkdir($basePath . '/storage/app/public', 0775, true);
        mkdir($basePath . '/public', 0775, true);
        touch($basePath . '/storage/app/public/avatar.jpg');

        config(['filesystems.disks.public' => [
            'driver' => 'local',
            'root' => $basePath . '/storage/app/public',
        ], 'filesystems.links' => [$basePath . '/public/storage' => $basePath . '/storage/app/public']]);

        $diagnostic = new PublicStorageLinkExists;
        $result = $diagnostic->check();
        $fix = $diagnostic->fix(DiagnosticResult::fail('missing'));

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Create the public storage link using `php artisan storage:link`?', $result->confirmation);
        $this->assertSame('Create the public storage link with `php artisan storage:link`.', $result->remediation);
        $this->assertEquals([Link::docs('filesystem', 'the-public-disk')], $result->links);
        $this->assertSame('pass', $fix->status->value);
        $this->assertSame('pass', $diagnostic->check()->status->value);
    }

    public function testUsesTheConfiguredPublicPathWhenCheckingTheStorageLink(): void
    {
        $basePath = $this->basePath;
        $publicPath = $basePath . '/web';

        mkdir($basePath . '/storage/app/public', 0775, true);
        mkdir($publicPath, 0775, true);
        touch($basePath . '/storage/app/public/avatar.jpg');
        symlink($basePath . '/storage/app/public', $publicPath . '/storage');

        $this->app->usePublicPath($publicPath);
        config(['filesystems.disks.public' => [
            'driver' => 'local',
            'root' => $basePath . '/storage/app/public',
        ], 'filesystems.links' => [$publicPath . '/storage' => $basePath . '/storage/app/public']]);

        $result = (new PublicStorageLinkExists)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('The public storage link exists.', $result->summary);
    }

    #[DataProvider('placeholders')]
    public function testSkipsWhenPublicStorageOnlyContainsAPlaceholder(string $placeholder): void
    {
        $basePath = $this->basePath;
        mkdir($basePath . '/storage/app/public', 0775, true);
        mkdir($basePath . '/public', 0775, true);
        touch($basePath . '/storage/app/public/' . $placeholder);

        config(['filesystems.disks.public' => [
            'driver' => 'local',
            'root' => $basePath . '/storage/app/public',
        ], 'filesystems.links' => [$basePath . '/public/storage' => $basePath . '/storage/app/public']]);

        $result = (new PublicStorageLinkExists)->check();

        $this->assertSame('skip', $result->status->value);
        $this->assertSame('The public storage directory does not contain public files.', $result->summary);
    }

    /**
     * Provide supported directory placeholders.
     */
    public static function placeholders(): array
    {
        return [['.gitignore'], ['.gitkeep']];
    }

    public function testRepairsConfiguredLinksForACustomRootAndAcceptsRelativeLinks(): void
    {
        $root = $this->basePath . '/assets';
        mkdir($root);
        touch($root . '/avatar.jpg');
        $existing = $this->basePath . '/existing';
        $missing = $this->basePath . '/uploads';
        symlink('assets', $existing);
        config([
            'filesystems.disks.public' => ['driver' => 'local', 'root' => $root],
            'filesystems.links' => [$existing => $root, $missing => $root, $this->basePath . '/unrelated' => '/unrelated'],
        ]);
        $diagnostic = new PublicStorageLinkExists;
        $result = $diagnostic->check();

        $this->assertTrue($result->fixable);
        $this->assertStringContainsString($missing, $result->details);
        $this->assertStringNotContainsString($existing, $result->details);
        $this->assertSame('pass', $diagnostic->fix($result)->status->value);
        $this->assertSame('pass', $diagnostic->check()->status->value);
        $this->assertSame('assets', readlink($existing));
    }

    #[DataProvider('conflicts')]
    public function testPreservesConflictingPathsAndDoesNotOfferAMixedRepair(string $kind, string $code): void
    {
        $root = $this->basePath . '/assets';
        mkdir($root);
        touch($root . '/avatar.jpg');
        $link = $this->basePath . '/uploads';

        match ($kind) {
            'wrong' => symlink($this->basePath, $link),
            'dangling' => symlink($this->basePath . '/missing-target', $link),
            'file' => file_put_contents($link, 'keep'),
            'directory' => mkdir($link),
        };
        config([
            'filesystems.disks.public' => ['driver' => 'local', 'root' => $root],
            'filesystems.links' => [$link => $root, $this->basePath . '/missing' => $root],
        ]);
        $kernel = m::mock(Kernel::class);
        $kernel->shouldNotReceive('call');
        Artisan::swap($kernel);
        $diagnostic = new PublicStorageLinkExists;
        $result = $diagnostic->check();

        $this->assertSame('public-storage-link-exists.' . $code, $result->code);
        $this->assertFalse($result->fixable);
        $this->assertSame('fail', $diagnostic->fix($result)->status->value);

        match ($kind) {
            'wrong' => $this->assertSame($this->basePath, readlink($link)),
            'dangling' => $this->assertSame($this->basePath . '/missing-target', readlink($link)),
            'file' => $this->assertSame('keep', file_get_contents($link)),
            'directory' => $this->assertDirectoryExists($link),
        };
    }

    /**
     * Provide paths that require an explicit user decision.
     */
    public static function conflicts(): array
    {
        return [
            ['wrong', 'wrong-link'], ['dangling', 'wrong-link'],
            ['file', 'conflicting-path'], ['directory', 'conflicting-path'],
        ];
    }

    #[DataProvider('commandFailures')]
    public function testReportsWhenTheCommandDoesNotRepairTheLink(int $exitCode, ?string $exceptionClass): void
    {
        $root = $this->basePath . '/assets';
        mkdir($root);
        touch($root . '/avatar.jpg');
        config([
            'filesystems.disks.public' => ['driver' => 'local', 'root' => $root],
            'filesystems.links' => [$this->basePath . '/uploads' => $root],
        ]);
        $kernel = m::mock(Kernel::class);
        Artisan::swap($kernel);
        $command = $kernel->shouldReceive('call')->once()->with('storage:link');

        if ($exceptionClass !== null) {
            $exception = new $exceptionClass('The link could not be created.');
            $command->andThrow($exception);

            if ($exception instanceof CanceledException) {
                $this->expectExceptionObject($exception);
            }
        } else {
            $command->andReturn($exitCode);
            $kernel->shouldReceive('output')->once()->andReturn('The link could not be created.');
        }

        $diagnostic = new PublicStorageLinkExists;

        $result = $diagnostic->fix($diagnostic->check());

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('The link could not be created.', $result->details);

        if ($exceptionClass === null) {
            $this->assertStringContainsString($this->basePath . '/uploads', $result->details);
        }
    }

    /**
     * Provide unsuccessful repairs, including a zero exit without a link.
     */
    public static function commandFailures(): array
    {
        return [
            'zero exit without link' => [0, null],
            'nonzero exit' => [1, null],
            'exception' => [1, RuntimeException::class],
            'cancellation' => [1, CanceledException::class],
        ];
    }

    #[DataProvider('disksWithoutLinks')]
    public function testSkipsDisksWithoutApplicableLocalLinks(array $disk, string $code): void
    {
        config(['filesystems.disks.public' => $disk, 'filesystems.links' => []]);

        $result = (new PublicStorageLinkExists)->check();

        $this->assertSame('skip', $result->status->value);
        $this->assertSame('public-storage-link-exists.' . $code, $result->code);
    }

    /**
     * Provide disks that do not need local links.
     */
    public static function disksWithoutLinks(): array
    {
        return [
            [['driver' => 's3'], 'not-default-local-path'],
            [['driver' => 'local', 'root' => '/unused'], 'not-configured'],
        ];
    }
}
