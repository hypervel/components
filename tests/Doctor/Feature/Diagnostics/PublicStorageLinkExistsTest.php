<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\PublicStorageLinkExists;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Process\FakeProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

        Process::fake(function () use ($basePath): FakeProcessResult {
            symlink($basePath . '/storage/app/public', $basePath . '/public/storage');

            return Process::result(output: 'The [public/storage] link has been connected.');
        });

        $diagnostic = new PublicStorageLinkExists;
        $result = $diagnostic->check();
        $fix = $diagnostic->fix(DiagnosticResult::fail('missing'));

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, 'artisan', 'storage:link']
            && $process->path === $basePath);

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
        Process::fake(function () use ($missing): FakeProcessResult {
            symlink('assets', $missing);

            return Process::result();
        });
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
        Process::fake();
        $diagnostic = new PublicStorageLinkExists;
        $result = $diagnostic->check();

        $this->assertSame('public-storage-link-exists.' . $code, $result->code);
        $this->assertFalse($result->fixable);
        $this->assertSame('fail', $diagnostic->fix($result)->status->value);
        Process::assertNothingRan();

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

    public function testSuccessfulCommandWithoutTheExpectedLinkDoesNotReportARepair(): void
    {
        $root = $this->basePath . '/assets';
        mkdir($root);
        touch($root . '/avatar.jpg');
        config([
            'filesystems.disks.public' => ['driver' => 'local', 'root' => $root],
            'filesystems.links' => [$this->basePath . '/uploads' => $root],
        ]);
        Process::fake(['*' => Process::result(output: 'The link already exists.')]);
        $diagnostic = new PublicStorageLinkExists;

        $result = $diagnostic->fix($diagnostic->check());

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString($this->basePath . '/uploads', $result->details);
        $this->assertStringContainsString('The link already exists.', $result->details);
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
