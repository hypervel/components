<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\PHPStan\Fixtures\RuntimeInputs\Package\FrenchGreeterServiceProvider;
use Hypervel\Tests\PHPStan\Fixtures\RuntimeInputs\Package\PackageServiceProvider;
use Symfony\Component\Process\Process;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function rmdir;
use function symlink;
use function touch;
use function unlink;

/**
 * A package's analysis boots a disposable Testbench copy of its skeleton in every process, so
 * what the boot decides depends on the skeleton, the Testbench YAML and the environment.
 */
class RuntimeInputsPackageDependencyTest extends ResultCacheTestCase
{
    /**
     * The diagnostic while the English greeter is bound.
     */
    private const string ENGLISH = 'Usage.php:11 method.notFound';

    public function testPackageInputChangesReanalyzeTheirConsumers(): void
    {
        $packagePath = $this->createPackage();
        $skeletonPath = $packagePath . '/skeleton';
        // The test models a standalone analysis, so it drops ParaTest's worker token, which
        // would make Testbench give the SQLite connection a per-worker file.
        $unset = ['PACKAGE_GREETER' => false, 'MYSQL_DATABASE' => false, 'TEST_TOKEN' => false];

        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));

        // The second run boots another runtime copy.
        $process = $this->analysePackage($packagePath, $unset);
        $this->assertSame([self::ENGLISH], $this->diagnostics($process, 1));
        $this->assertReanalyzed(0, $process);

        // A skeleton configuration file.
        $this->edit($skeletonPath . '/config/package.php', "'english'", "'french'");
        $this->assertSame([], $this->diagnostics($this->analysePackage($packagePath, $unset), 0));
        $this->edit($skeletonPath . '/config/package.php', "'french'", "'english'");
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));

        // The Testbench YAML's environment.
        $this->edit($packagePath . '/testbench.yaml', "hypervel: ./skeleton\n", "hypervel: ./skeleton\nenv:\n  PACKAGE_GREETER: french\n");
        $this->assertSame([], $this->diagnostics($this->analysePackage($packagePath, $unset), 0));
        $this->edit($packagePath . '/testbench.yaml', "env:\n  PACKAGE_GREETER: french\n", '');
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));

        // A database override Testbench reads from the process environment.
        $this->assertSame([], $this->diagnostics($this->analysePackage($packagePath, [...$unset, 'MYSQL_DATABASE' => 'french']), 0));
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));

        // Testbench makes the testing connection the default while the skeleton's SQLite file is missing.
        touch($skeletonPath . '/database/database.sqlite');
        $this->assertSame([], $this->diagnostics($this->analysePackage($packagePath, $unset), 0));
        unlink($skeletonPath . '/database/database.sqlite');
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));

        $this->assertStringNotContainsString("env-pattern\t*", (string) file_get_contents($packagePath . '/cache/resultCache.php'));
    }

    public function testVendorSourceChangesReanalyzeTheirConsumers(): void
    {
        $packagePath = $this->createPackage();
        $skeletonVendorPath = $packagePath . '/skeleton/vendor';
        $unset = ['PACKAGE_GREETER' => false, 'MYSQL_DATABASE' => false, 'TEST_TOKEN' => false];
        $englishVendorPath = $this->createVendor($this->tempDir . '/english', french: false);
        $frenchVendorPath = $this->createVendor($this->tempDir . '/french', french: true);

        // Without a skeleton vendor directory, the runtime copy links the package's.
        symlink($englishVendorPath, $packagePath . '/vendor');
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));

        $process = $this->analysePackage($packagePath, $unset);
        $this->assertSame([self::ENGLISH], $this->diagnostics($process, 1));
        $this->assertReanalyzed(0, $process);

        // The package's vendor link is repointed while its old target stays.
        unlink($packagePath . '/vendor');
        symlink($frenchVendorPath, $packagePath . '/vendor');
        $this->assertSame([], $this->diagnostics($this->analysePackage($packagePath, $unset), 0));

        // An empty skeleton vendor directory is copied instead, so no package is discovered.
        mkdir($skeletonVendorPath);
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));
        rmdir($skeletonVendorPath);
        $this->assertSame([], $this->diagnostics($this->analysePackage($packagePath, $unset), 0));

        // Skeleton metadata appears after the package's was used.
        unlink($packagePath . '/vendor');
        symlink($englishVendorPath, $packagePath . '/vendor');
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));
        $this->createVendor($skeletonVendorPath, french: true);
        $this->assertSame([], $this->diagnostics($this->analysePackage($packagePath, $unset), 0));
        (new Filesystem)->deleteDirectory($skeletonVendorPath);
        $this->assertSame([self::ENGLISH], $this->diagnostics($this->analysePackage($packagePath, $unset), 1));
    }

    /**
     * Create a package whose Testbench YAML names a copy of the default skeleton.
     */
    private function createPackage(): string
    {
        $packagePath = $this->tempDir . '/package';
        (new Filesystem)->copyDirectory(dirname(__DIR__, 2) . '/src/testbench/hypervel', $packagePath . '/skeleton');

        file_put_contents($packagePath . '/skeleton/config/package.php', "<?php\n\nreturn ['greeter' => env('PACKAGE_GREETER', 'english')];\n");
        file_put_contents($packagePath . '/testbench.yaml', "hypervel: ./skeleton\n");
        // The provider is declared only in the package's own Composer metadata, as an installing application would discover it.
        file_put_contents($packagePath . '/composer.json', json_encode([
            'name' => 'hypervel-phpstan/runtime-inputs-package',
            'extra' => ['hypervel' => ['providers' => [PackageServiceProvider::class]]],
        ], JSON_THROW_ON_ERROR));

        return $packagePath;
    }

    /**
     * Create a vendor directory whose Composer metadata lists the French greeter's package, or no packages.
     */
    private function createVendor(string $vendorPath, bool $french): string
    {
        $packages = $french ? [[
            'name' => 'hypervel-phpstan/french-greeter',
            'extra' => ['hypervel' => ['providers' => [FrenchGreeterServiceProvider::class]]],
        ]] : [];

        mkdir($vendorPath . '/composer', 0777, true);
        file_put_contents($vendorPath . '/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));

        return $vendorPath;
    }

    /**
     * Run PHPStan from the package over the package fixture directory, one file per job.
     *
     * @param array<string, false|string> $environment variables to set, or to unset with false, over the test's own
     */
    private function analysePackage(string $packagePath, array $environment): Process
    {
        $repositoryPath = dirname(__DIR__, 2);
        $fixturePath = __DIR__ . '/Fixtures/RuntimeInputs/Package';

        file_put_contents($packagePath . '/phpstan.neon', <<<NEON
            includes:
                - {$repositoryPath}/src/phpstan/extension.neon
            parameters:
                level: 5
                tmpDir: {$packagePath}/cache
                paths:
                    - {$fixturePath}
                parallel:
                    jobSize: 1
            NEON);

        $process = new Process(
            [PHP_BINARY, $repositoryPath . '/vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon', '--memory-limit=1G', '--no-progress', '--error-format=json', '--no-ansi', '-vv'],
            $packagePath,
            $environment,
            timeout: 300,
        );
        $process->run();

        return $process;
    }
}
