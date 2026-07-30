<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Contracts\NodePackageManager as NodePackageManagerContract;
use Hypervel\Support\NodePackageManager;
use Hypervel\Support\NodePackageManagers\Bun;
use Hypervel\Support\NodePackageManagers\Npm;
use Hypervel\Support\NodePackageManagers\Pnpm;
use Hypervel\Support\NodePackageManagers\Yarn;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

class SupportNodePackageManagerTest extends TestCase
{
    protected string $tempDirectory;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDirectory = ParallelTesting::tempDir('SupportNodePackageManagerTest');
        $this->filesystem->deleteDirectory($this->tempDirectory);
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testNpmRunCommand(): void
    {
        $npm = new Npm;

        $this->assertSame('npm run dev', $npm->getRunCommand('dev'));
    }

    public function testNpmExecCommand(): void
    {
        $npm = new Npm;

        $this->assertSame('npx concurrently', $npm->getExecCommand('concurrently'));
    }

    public function testNpmMatches(): void
    {
        $directory = $this->makeDirectory('npm-matches');
        touch($directory . '/package-lock.json');

        $original = getcwd();
        chdir($directory);

        try {
            $this->assertTrue(Npm::matches());
        } finally {
            chdir($original);
        }
    }

    public function testNpmDoesNotMatchWithoutLockFile(): void
    {
        $directory = $this->makeDirectory('npm-no-match');

        $original = getcwd();
        chdir($directory);

        try {
            $this->assertFalse(Npm::matches());
        } finally {
            chdir($original);
        }
    }

    public function testYarnRunCommand(): void
    {
        $yarn = new Yarn;

        $this->assertSame('yarn run dev', $yarn->getRunCommand('dev'));
    }

    public function testYarnExecCommand(): void
    {
        $yarn = new Yarn;

        $this->assertSame('yarn run concurrently', $yarn->getExecCommand('concurrently'));
    }

    public function testYarnMatches(): void
    {
        $directory = $this->makeDirectory('yarn-matches');
        touch($directory . '/yarn.lock');

        $original = getcwd();
        chdir($directory);

        try {
            $this->assertTrue(Yarn::matches());
        } finally {
            chdir($original);
        }
    }

    public function testPnpmRunCommand(): void
    {
        $pnpm = new Pnpm;

        $this->assertSame('pnpm run dev', $pnpm->getRunCommand('dev'));
    }

    public function testPnpmExecCommand(): void
    {
        $pnpm = new Pnpm;

        $this->assertSame('pnpm exec concurrently', $pnpm->getExecCommand('concurrently'));
    }

    public function testPnpmMatches(): void
    {
        $directory = $this->makeDirectory('pnpm-matches');
        touch($directory . '/pnpm-lock.yaml');

        $original = getcwd();
        chdir($directory);

        try {
            $this->assertTrue(Pnpm::matches());
        } finally {
            chdir($original);
        }
    }

    public function testBunRunCommand(): void
    {
        $bun = new Bun;

        $this->assertSame('bun run dev', $bun->getRunCommand('dev'));
    }

    public function testBunExecCommand(): void
    {
        $bun = new Bun;

        $this->assertSame('bunx concurrently', $bun->getExecCommand('concurrently'));
    }

    public function testBunMatchesWithBunLock(): void
    {
        $directory = $this->makeDirectory('bun-lock-matches');
        touch($directory . '/bun.lock');

        $original = getcwd();
        chdir($directory);

        try {
            $this->assertTrue(Bun::matches());
        } finally {
            chdir($original);
        }
    }

    public function testBunMatchesWithBunLockb(): void
    {
        $directory = $this->makeDirectory('bun-lockb-matches');
        touch($directory . '/bun.lockb');

        $original = getcwd();
        chdir($directory);

        try {
            $this->assertTrue(Bun::matches());
        } finally {
            chdir($original);
        }
    }

    public function testManagerDelegatesToInjectedPackageManager(): void
    {
        $mock = new class implements NodePackageManagerContract {
            public static function matches(): bool
            {
                return true;
            }

            public function getRunCommand(string $command): string
            {
                return "custom run {$command}";
            }

            public function getExecCommand(string $command): string
            {
                return "custom exec {$command}";
            }
        };

        $manager = new NodePackageManager($mock);

        $this->assertSame('custom run dev', $manager->getRunCommand('dev'));
        $this->assertSame('custom exec vite', $manager->getExecCommand('vite'));
    }

    public function testManagerDetectsPackageManagerWhenNoneInjected(): void
    {
        $directory = $this->makeDirectory('detect-npm');
        touch($directory . '/package-lock.json');

        $original = getcwd();
        chdir($directory);

        try {
            $manager = new NodePackageManager;

            $this->assertSame('npm run dev', $manager->getRunCommand('dev'));
            $this->assertSame('npx vite', $manager->getExecCommand('vite'));
        } finally {
            chdir($original);
        }
    }

    public function testDetectionPriorityBunOverNpm(): void
    {
        $directory = $this->makeDirectory('priority-bun');
        touch($directory . '/bun.lock');
        touch($directory . '/package-lock.json');

        $original = getcwd();
        chdir($directory);

        try {
            $manager = new NodePackageManager;

            $this->assertSame('bun run dev', $manager->getRunCommand('dev'));
        } finally {
            chdir($original);
        }
    }

    public function testDetectionUsesPackageManagerPriorityWithinTheNearestDirectory(): void
    {
        $directory = $this->makeDirectory('priority-order');
        touch($directory . '/package-lock.json');
        touch($directory . '/yarn.lock');

        $original = getcwd();
        chdir($directory);

        try {
            $this->assertInstanceOf(Yarn::class, (new NodePackageManager)->packageManager());

            touch($directory . '/pnpm-lock.yaml');

            $this->assertInstanceOf(Pnpm::class, (new NodePackageManager)->packageManager());

            touch($directory . '/bun.lock');

            $this->assertInstanceOf(Bun::class, (new NodePackageManager)->packageManager());
        } finally {
            chdir($original);
        }
    }

    public function testDetectionWalksAncestorDirectories(): void
    {
        $directory = $this->makeDirectory('ancestor-lock');
        $nestedDirectory = $directory . '/apps/starter';
        mkdir($nestedDirectory, 0777, true);
        touch($directory . '/pnpm-lock.yaml');

        $original = getcwd();
        chdir($nestedDirectory);

        try {
            $manager = new NodePackageManager;

            $this->assertInstanceOf(Pnpm::class, $manager->packageManager());
            $this->assertSame('pnpm run dev', $manager->getRunCommand('dev'));
            $this->assertSame('pnpm exec concurrently', $manager->getExecCommand('concurrently'));
        } finally {
            chdir($original);
        }
    }

    public function testDetectionUsesTheNearestLockFileBeforeAncestorPriority(): void
    {
        $directory = $this->makeDirectory('nearest-lock');
        $nestedDirectory = $directory . '/app';
        mkdir($nestedDirectory);
        touch($directory . '/bun.lock');
        touch($nestedDirectory . '/package-lock.json');

        $original = getcwd();
        chdir($nestedDirectory);

        try {
            $this->assertInstanceOf(Npm::class, (new NodePackageManager)->packageManager());
        } finally {
            chdir($original);
        }
    }

    public function testDetectionFallsBackToNpm(): void
    {
        $directory = $this->makeDirectory('fallback-npm');

        $original = getcwd();
        chdir($directory);

        try {
            $manager = new NodePackageManager;

            $this->assertSame('npm run dev', $manager->getRunCommand('dev'));
        } finally {
            chdir($original);
        }
    }

    public function testPackageManagerMethodReturnsDetectedManager(): void
    {
        $directory = $this->makeDirectory('package-manager-method');
        touch($directory . '/yarn.lock');

        $original = getcwd();
        chdir($directory);

        try {
            $manager = new NodePackageManager;

            $this->assertInstanceOf(Yarn::class, $manager->packageManager());
        } finally {
            chdir($original);
        }
    }

    /**
     * Create a temporary test directory.
     */
    protected function makeDirectory(string $name): string
    {
        $directory = $this->tempDirectory . '/' . $name;
        mkdir($directory);

        return $directory;
    }
}
