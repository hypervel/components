<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Composer\InstalledVersions;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Env;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use UnexpectedValueException;

use function Hypervel\Testbench\testbench_path;

class BootstrapperTest extends TestCase
{
    #[Test]
    public function testFlushStateKeepsRuntimePathForShutdownCleanup(): void
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $runtimePath = '/tmp/hypervel-components-testbench-flush-state';
        $previousConfiguration = $reflection->getStaticPropertyValue('configuration');
        $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

        try {
            $reflection->setStaticPropertyValue('configuration', new Config);
            $reflection->setStaticPropertyValue('runtimePath', $runtimePath);

            Bootstrapper::flushState();

            $this->assertNull($reflection->getStaticPropertyValue('configuration'));
            $this->assertSame($runtimePath, $reflection->getStaticPropertyValue('runtimePath'));
        } finally {
            $reflection->setStaticPropertyValue('configuration', $previousConfiguration);
            $reflection->setStaticPropertyValue('runtimePath', $previousRuntimePath);
        }
    }

    #[Test]
    public function itRethrowsRuntimeDirectoryDeletionFailuresWhenTheDirectoryRemains(): void
    {
        $filesystem = new RuntimeDirectoryStillPresentFilesystem;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('runtime directory still present');

        try {
            $this->deleteRuntimeDirectoryWithFilesystem($filesystem);
        } finally {
            $this->assertSame(1, $filesystem->deleteAttempts);
        }
    }

    #[Test]
    public function itRefusesToPublishOverAnExistingRuntimePath(): void
    {
        $token = 'bootstrapper-existing-runtime-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $packagePath = $this->temporaryDirectory('existing-runtime-package');
        $sourcePath = $this->temporaryDirectory('existing-runtime-source');
        $runtimePath = $this->runtimeDirectory($token, getmypid());
        $filesystem = new UnremovableRuntimePathFilesystem($runtimePath);

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        mkdir($runtimePath, 0777, true);
        file_put_contents($runtimePath . '/stale.txt', 'stale');

        try {
            // The refusal happens before Bootstrapper replaces the worker's
            // process-owned runtime identity with this fabricated path.
            $this->withRuntimeCopyEnvironment($token, false, function () use ($filesystem, $sourcePath, $packagePath, $runtimePath): void {
                $this->withBootstrapperFilesystem($filesystem, function () use ($filesystem, $sourcePath, $packagePath, $runtimePath): void {
                    try {
                        $this->createRuntimeCopy($sourcePath, $packagePath);
                        $this->fail('Expected the existing runtime path to be rejected.');
                    } catch (RuntimeException $exception) {
                        $this->assertStringContainsString($runtimePath, $exception->getMessage());
                    }

                    $this->assertFalse($filesystem->copyAttempted);
                    $this->assertFileExists($runtimePath . '/stale.txt');
                });
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itCreatesTheRuntimeRootWithOwnerOnlyPermissions(): void
    {
        $packagePath = $this->temporaryDirectory('private-runtime-package');
        $sourcePath = $this->temporaryDirectory('private-runtime-source');
        $runtimePath = null;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-private-runtime', false, function () use ($sourcePath, $packagePath, &$runtimePath): void {
                $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

                $this->assertSame(0700, fileperms($runtimePath) & 0777);
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itFailsWhenTheRuntimeRootCannotBeCreated(): void
    {
        $packagePath = $this->temporaryDirectory('failed-root-package');
        $sourcePath = $this->temporaryDirectory('failed-root-source');
        $filesystem = new FailedRuntimeRootFilesystem;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-failed-root', false, function () use ($filesystem, $sourcePath, $packagePath): void {
                $this->withBootstrapperFilesystem($filesystem, function () use ($filesystem, $sourcePath, $packagePath): void {
                    try {
                        $this->createRuntimeCopy($sourcePath, $packagePath);
                        $this->fail('Expected runtime root creation to fail.');
                    } catch (RuntimeException $exception) {
                        $this->assertStringContainsString('Unable to create runtime path', $exception->getMessage());
                    }

                    $this->assertFalse($filesystem->copyAttempted);
                });
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
        }
    }

    #[Test]
    public function itPublishesWhenAnUnrelatedStaleRuntimeCannotBeRemoved(): void
    {
        $token = 'bootstrapper-unremovable-stale-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $staleToken = 'bootstrapper-foreign-stale-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $packagePath = $this->temporaryDirectory('unremovable-stale-package');
        $sourcePath = $this->temporaryDirectory('unremovable-stale-source');
        $runtimePath = $this->runtimeDirectory($token, getmypid());
        $staleRuntimePath = $this->runtimeDirectory($staleToken, getmypid());
        $filesystem = new UnremovableRuntimePathFilesystem($staleRuntimePath);

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        mkdir($staleRuntimePath, 0777, true);
        file_put_contents($sourcePath . '/fresh.txt', 'fresh');

        try {
            $this->withRuntimeCopyEnvironment($token, false, function () use ($filesystem, $sourcePath, $packagePath, $runtimePath, $staleRuntimePath): void {
                $this->withBootstrapperFilesystem($filesystem, function () use ($filesystem, $sourcePath, $packagePath, $runtimePath, $staleRuntimePath): void {
                    $this->assertSame($runtimePath, $this->createRuntimeCopy($sourcePath, $packagePath));
                    $this->assertTrue($filesystem->copyAttempted);
                    $this->assertFileExists($runtimePath . '/fresh.txt');
                    $this->assertDirectoryExists($staleRuntimePath);
                });
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
            $this->deleteDirectory($staleRuntimePath);
        }
    }

    #[Test]
    public function itRollsBackAPartialRuntimeCopyWhenDirectoryCopyingFails(): void
    {
        $packagePath = $this->temporaryDirectory('failed-copy-package');
        $sourcePath = $this->temporaryDirectory('failed-copy-source');
        $filesystem = new FailedRuntimeCopyFilesystem;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-failed-copy', false, function () use ($filesystem, $sourcePath, $packagePath): void {
                $this->withBootstrapperFilesystem($filesystem, function () use ($filesystem, $sourcePath, $packagePath): void {
                    $reflection = new ReflectionClass(Bootstrapper::class);
                    $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

                    try {
                        $this->createRuntimeCopy($sourcePath, $packagePath);
                        $this->fail('Expected runtime copy creation to fail.');
                    } catch (RuntimeException $exception) {
                        $this->assertStringContainsString('Unable to create the Testbench runtime copy', $exception->getMessage());
                    }

                    $this->assertNotNull($filesystem->runtimePath);
                    $this->assertDirectoryDoesNotExist($filesystem->runtimePath);
                    $this->assertSame($previousRuntimePath, $reflection->getStaticPropertyValue('runtimePath'));
                });
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($filesystem->runtimePath);
        }
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function itDoesNotDeleteTheActiveRuntimeWhenAnOverlayFails(): void
    {
        $token = 'bootstrapper-failed-overlay-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $packagePath = $this->temporaryDirectory('failed-overlay-package');
        $sourcePath = $this->temporaryDirectory('failed-overlay-source');
        $runtimePath = $this->runtimeDirectory($token, getmypid());
        $filesystem = new FailedRuntimeOverlayFilesystem;
        $reflection = new ReflectionClass(Bootstrapper::class);

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        mkdir($runtimePath, 0777, true);
        file_put_contents($runtimePath . '/active.txt', 'active');

        try {
            $this->withRuntimeCopyEnvironment($token, false, function () use ($reflection, $filesystem, $sourcePath, $packagePath, $runtimePath): void {
                $reflection->setStaticPropertyValue('runtimePath', $runtimePath);

                $this->withBootstrapperFilesystem($filesystem, function () use ($filesystem, $sourcePath, $packagePath, $runtimePath): void {
                    $this->expectException(RuntimeException::class);
                    $this->expectExceptionMessage('Unable to create the Testbench runtime copy');

                    try {
                        $this->createRuntimeCopy($sourcePath, $packagePath);
                    } finally {
                        $this->assertSame(0, $filesystem->makeDirectoryAttempts);
                        $this->assertFileExists($runtimePath . '/active.txt');
                    }
                });
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRollsBackTheRuntimeCopyWhenProcessMarkerCreationFails(): void
    {
        $packagePath = $this->temporaryDirectory('failed-marker-package');
        $sourcePath = $this->temporaryDirectory('failed-marker-source');
        $failure = new RuntimeException('marker creation failed');
        $filesystem = new FailedRuntimeMarkerFilesystem($failure);

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        BootstrapperIdentityProbe::setStartIdentity('start-identity');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-failed-marker', false, function () use ($filesystem, $sourcePath, $packagePath, $failure): void {
                $this->withBootstrapperFilesystem($filesystem, function () use ($filesystem, $sourcePath, $packagePath, $failure): void {
                    $reflection = new ReflectionClass(Bootstrapper::class);
                    $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

                    try {
                        $this->createRuntimeCopy($sourcePath, $packagePath, BootstrapperIdentityProbe::class);
                        $this->fail('Expected process marker creation to fail.');
                    } catch (RuntimeException $exception) {
                        $this->assertSame($failure, $exception);
                    }

                    $this->assertNotNull($filesystem->runtimePath);
                    $this->assertDirectoryDoesNotExist($filesystem->runtimePath);
                    $this->assertSame($previousRuntimePath, $reflection->getStaticPropertyValue('runtimePath'));
                });
            });
        } finally {
            BootstrapperIdentityProbe::resetStartIdentity();
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($filesystem->runtimePath);
        }
    }

    #[Test]
    public function itCopiesThePackageEnvironmentFileIntoTheRuntimeCopy(): void
    {
        $packagePath = $this->temporaryDirectory('package-env');
        $sourcePath = $this->temporaryDirectory('skeleton-env');
        $runtimePath = null;

        mkdir($packagePath . DIRECTORY_SEPARATOR . 'workbench', 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($packagePath . DIRECTORY_SEPARATOR . 'workbench' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME=Workbench');
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'APP_NAME=Skeleton');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-package-env', true, function () use ($sourcePath, $packagePath, &$runtimePath): void {
                $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env');
                $this->assertSame('APP_NAME=Workbench', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRollsBackWhenThePackageEnvironmentFileCannotBeCopied(): void
    {
        $packagePath = $this->temporaryDirectory('failed-package-env');
        $sourcePath = $this->temporaryDirectory('failed-package-env-source');
        $filesystem = new FailedEnvironmentCopyFilesystem;

        mkdir($packagePath . DIRECTORY_SEPARATOR . 'workbench', 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($packagePath . DIRECTORY_SEPARATOR . 'workbench' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME=Workbench');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-failed-package-env', true, function () use ($filesystem, $sourcePath, $packagePath): void {
                $this->withBootstrapperFilesystem($filesystem, function () use ($filesystem, $sourcePath, $packagePath): void {
                    $this->expectException(RuntimeException::class);
                    $this->expectExceptionMessage('Unable to copy the Testbench environment file.');

                    try {
                        $this->createRuntimeCopy($sourcePath, $packagePath);
                    } finally {
                        $this->assertNotNull($filesystem->runtimePath);
                        $this->assertDirectoryDoesNotExist($filesystem->runtimePath);
                    }
                });
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($filesystem->runtimePath);
        }
    }

    #[Test]
    public function itCopiesTheSkeletonEnvironmentExampleIntoTheRuntimeCopyWhenNoPackageEnvironmentFileExists(): void
    {
        $packagePath = $this->temporaryDirectory('package-no-env');
        $sourcePath = $this->temporaryDirectory('skeleton-no-env');
        $runtimePath = null;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'REDIS_PASSWORD=null');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-skeleton-env', true, function () use ($sourcePath, $packagePath, &$runtimePath): void {
                $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env');
                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env.example');
                $this->assertSame('REDIS_PASSWORD=null', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itDoesNotCopyPackageOrSkeletonEnvironmentFilesOutsidePackageTesterMode(): void
    {
        $packagePath = $this->temporaryDirectory('package-raw-env');
        $sourcePath = $this->temporaryDirectory('skeleton-raw-env');
        $runtimePath = null;

        mkdir($packagePath . DIRECTORY_SEPARATOR . 'workbench', 0777, true);
        mkdir($sourcePath, 0777, true);
        file_put_contents($packagePath . DIRECTORY_SEPARATOR . 'workbench' . DIRECTORY_SEPARATOR . '.env', 'APP_NAME=Workbench');
        file_put_contents($sourcePath . DIRECTORY_SEPARATOR . '.env.example', 'APP_NAME=Skeleton');

        try {
            $this->withRuntimeCopyEnvironment('bootstrapper-raw-env', false, function () use ($sourcePath, $packagePath, &$runtimePath): void {
                $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);

                $this->assertFileDoesNotExist($runtimePath . DIRECTORY_SEPARATOR . '.env');
                $this->assertFileExists($runtimePath . DIRECTORY_SEPARATOR . '.env.example');
                $this->assertSame('APP_NAME=Skeleton', file_get_contents($runtimePath . DIRECTORY_SEPARATOR . '.env.example'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itWritesAPrivateProcessIncarnationMarker(): void
    {
        $packagePath = $this->temporaryDirectory('identity-package');
        $sourcePath = $this->temporaryDirectory('identity-skeleton');
        $runtimePath = null;

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);

        try {
            $this->withRuntimeCopyEnvironment(
                'bootstrapper-identity',
                false,
                function () use (
                    $sourcePath,
                    $packagePath,
                    &$runtimePath,
                ): void {
                    $runtimePath = $this->createRuntimeCopy($sourcePath, $packagePath);
                },
            );

            $marker = $runtimePath . DIRECTORY_SEPARATOR . '.testbench-process';
            $identity = json_decode((string) file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame([
                'pid' => getmypid(),
                'started_at' => BootstrapperIdentityProbe::startIdentity(getmypid()),
            ], $identity);
            $this->assertSame(0600, fileperms($marker) & 0777);
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itUsesThePrivateTestbenchConfigurationOnlyForTheComponentsMonorepo(): void
    {
        $workingPath = $this->temporaryDirectory('components-configuration');
        mkdir($workingPath, 0777, true);

        try {
            $this->withComposerRoot('hypervel/components', function () use ($workingPath): void {
                $configurationPath = $this->resolveConfigurationPath($workingPath);
                $config = Config::loadFromYaml($configurationPath);

                $this->assertSame(testbench_path(), $configurationPath);
                $this->assertSame(
                    ['Workbench\App\Providers\WorkbenchServiceProvider'],
                    $config['providers'],
                );
                $this->assertSame(['hypervel/components'], $config['dont-discover']);
            });
        } finally {
            $this->deleteDirectory($workingPath);
        }
    }

    #[Test]
    public function itUsesConsumerDefaultsWhenNoConfigurationFileExists(): void
    {
        $workingPath = $this->temporaryDirectory('consumer-configuration');
        mkdir($workingPath, 0777, true);

        try {
            $this->withComposerRoot('example/package', function () use ($workingPath): void {
                $configurationPath = $this->resolveConfigurationPath($workingPath);
                $config = Config::loadFromYaml($configurationPath);

                $this->assertSame($workingPath, $configurationPath);
                $this->assertSame([], $config['providers']);
                $this->assertSame([], $config['dont-discover']);
            });
        } finally {
            $this->deleteDirectory($workingPath);
        }
    }

    #[Test]
    public function itUsesASplitPackagesOwnConfigurationFile(): void
    {
        $workingPath = $this->temporaryDirectory('split-configuration');
        mkdir($workingPath, 0777, true);
        file_put_contents($workingPath . '/testbench.yaml', "env:\n  APP_NAME: Split\n");

        try {
            $this->withComposerRoot('hypervel/testbench', function () use ($workingPath): void {
                $configurationPath = $this->resolveConfigurationPath($workingPath);
                $config = Config::loadFromYaml($configurationPath);

                $this->assertSame($workingPath, $configurationPath);
                $this->assertSame(['APP_NAME="Split"'], $config['env']);
            });
        } finally {
            $this->deleteDirectory($workingPath);
        }
    }

    // Isolation gives the fixture a distinct live parent PID without sharing
    // the PHPUnit worker's process state with the stale sweep.
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function itPurgesStaleRuntimeCopiesAcrossWorkerTokens(): void
    {
        $token = 'bootstrapper-current-copy';
        $staleToken = 'bootstrapper-stale-copy';
        $packagePath = $this->temporaryDirectory('stale-copy-package');
        $sourcePath = $this->temporaryDirectory('stale-copy-source');
        $runtimePath = $this->runtimeDirectory($token, getmypid());
        $staleRuntimePath = $this->runtimeDirectory($staleToken, getmypid());
        $parentRuntimePath = $this->runtimeDirectory($staleToken, posix_getppid());

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        mkdir($staleRuntimePath, 0777, true);
        mkdir($parentRuntimePath, 0777, true);
        file_put_contents($sourcePath . '/fresh.txt', 'fresh');
        file_put_contents($staleRuntimePath . '/stale.txt', 'stale');
        file_put_contents($parentRuntimePath . '/active.txt', 'active');

        try {
            $this->assertTrue(posix_kill(posix_getppid(), 0));

            $this->withRuntimeCopyEnvironment($token, false, function () use ($sourcePath, $packagePath, $runtimePath, $staleRuntimePath, $parentRuntimePath): void {
                $this->assertSame($runtimePath, $this->createRuntimeCopy($sourcePath, $packagePath));
                $this->assertDirectoryDoesNotExist($staleRuntimePath);
                $this->assertSame('fresh', file_get_contents($runtimePath . '/fresh.txt'));
                $this->assertSame('active', file_get_contents($parentRuntimePath . '/active.txt'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
            $this->deleteDirectory($staleRuntimePath);
            $this->deleteDirectory($parentRuntimePath);
        }
    }

    // Isolation prevents the fabricated runtime identity from exposing the
    // PHPUnit worker's live runtime copy to the global stale sweep.
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function itPreservesTheActiveRuntimeCopy(): void
    {
        $token = 'bootstrapper-active-copy';
        $packagePath = $this->temporaryDirectory('active-copy-package');
        $sourcePath = $this->temporaryDirectory('active-copy-source');
        $runtimePath = $this->runtimeDirectory($token, getmypid());
        $reflection = new ReflectionClass(Bootstrapper::class);

        mkdir($packagePath, 0777, true);
        mkdir($sourcePath, 0777, true);
        mkdir($runtimePath, 0777, true);
        file_put_contents($sourcePath . '/fresh.txt', 'fresh');
        file_put_contents($runtimePath . '/active.txt', 'active');

        try {
            $this->withRuntimeCopyEnvironment($token, false, function () use ($reflection, $sourcePath, $packagePath, $runtimePath): void {
                $reflection->setStaticPropertyValue('runtimePath', $runtimePath);

                $this->assertSame($runtimePath, $this->createRuntimeCopy($sourcePath, $packagePath));
                $this->assertSame('active', file_get_contents($runtimePath . '/active.txt'));
                $this->assertSame('fresh', file_get_contents($runtimePath . '/fresh.txt'));
            });
        } finally {
            $this->deleteDirectory($packagePath);
            $this->deleteDirectory($sourcePath);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRecognizesAMatchingServeProcessIdentity(): void
    {
        [$process, $pipes, $pid] = $this->startTitledProcess();
        $runtimePath = null;

        try {
            $startIdentity = BootstrapperIdentityProbe::startIdentity($pid);
            $this->assertNotNull($startIdentity);
            [$runtimePath] = $this->createServeIdentityRuntime($pid, $startIdentity);

            $this->assertTrue(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            $this->stopProcess($process, $pipes, $pid);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAProcessWithoutTheRuntimePidFile(): void
    {
        [$process, $pipes, $pid] = $this->startTitledProcess();
        $runtimePath = null;

        try {
            $startIdentity = BootstrapperIdentityProbe::startIdentity($pid);
            $this->assertNotNull($startIdentity);
            [$runtimePath] = $this->createServeIdentityRuntime($pid, $startIdentity, false);

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            $this->stopProcess($process, $pipes, $pid);
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAReusedPidWithADifferentStartIdentity(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            BootstrapperIdentityProbe::setStartIdentity('different-start-identity');

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetStartIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAMalformedProcessMarker(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            file_put_contents($runtimePath . '/.testbench-process', '{invalid');
            BootstrapperIdentityProbe::setStartIdentity('start-identity');

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetStartIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAnUnreadableProcessIdentity(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            BootstrapperIdentityProbe::setStartIdentity(null);

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetStartIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsAMismatchedRuntimePidFile(): void
    {
        [$runtimePath, $pid] = $this->createServeIdentityRuntime();

        try {
            file_put_contents(
                $runtimePath . '/storage/framework/hypervel.pid',
                (string) ($pid + 1),
            );
            BootstrapperIdentityProbe::setStartIdentity('start-identity');

            $this->assertFalse(
                BootstrapperIdentityProbe::matchesServeProcess($pid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetStartIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    #[Test]
    public function itRejectsADeadPidBeforeInspectingItsIdentity(): void
    {
        [$runtimePath] = $this->createServeIdentityRuntime();
        $deadPid = 2_000_000_000;

        try {
            file_put_contents(
                $runtimePath . '/storage/framework/hypervel.pid',
                (string) $deadPid,
            );
            BootstrapperIdentityProbe::setStartIdentity('start-identity');

            $this->assertFalse(
                BootstrapperIdentityProbe::isOrphanedServe($deadPid, $runtimePath),
            );
        } finally {
            BootstrapperIdentityProbe::resetStartIdentity();
            $this->deleteDirectory($runtimePath);
        }
    }

    /**
     * Create a temporary test directory.
     */
    private function temporaryDirectory(string $name): string
    {
        return ParallelTesting::tempDir(
            "BootstrapperTest-{$name}-" . bin2hex(random_bytes(6)),
        );
    }

    /**
     * Get the Testbench runtime directory for a worker token and process.
     */
    private function runtimeDirectory(string $token, int $pid): string
    {
        $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        return $tempDir . "/hypervel-components-testbench-{$token}-{$pid}";
    }

    /**
     * Create a runtime copy through Bootstrapper's protected method.
     */
    private function createRuntimeCopy(
        string $sourcePath,
        string $workingPath,
        string $bootstrapper = Bootstrapper::class,
    ): string {
        $method = new ReflectionMethod($bootstrapper, 'createRuntimeCopy');
        $method->setAccessible(true);

        return $method->invoke(null, $sourcePath, $workingPath);
    }

    /**
     * Resolve the configuration path through Bootstrapper's protected method.
     */
    private function resolveConfigurationPath(string $workingPath): string
    {
        $method = new ReflectionMethod(Bootstrapper::class, 'resolveConfigurationPath');
        $method->setAccessible(true);

        return $method->invoke(null, $workingPath);
    }

    /**
     * Run a callback with a specific Composer root package name.
     */
    private function withComposerRoot(string $name, callable $callback): void
    {
        $installed = require dirname(__DIR__, 2) . '/vendor/composer/installed.php';
        $reloaded = $installed;
        $reloaded['root']['name'] = $name;
        $canGetVendors = new ReflectionProperty(InstalledVersions::class, 'canGetVendors');
        $previousCanGetVendors = $canGetVendors->getValue();

        $canGetVendors->setValue(null, false);
        InstalledVersions::reload($reloaded);

        try {
            $callback();
        } finally {
            InstalledVersions::reload($installed);
            $canGetVendors->setValue(null, $previousCanGetVendors);
        }
    }

    /**
     * Run a callback with a specific Bootstrapper filesystem.
     */
    private function withBootstrapperFilesystem(Filesystem $filesystem, callable $callback): void
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $previousFilesystem = $reflection->getStaticPropertyValue('filesystem');

        try {
            $reflection->setStaticPropertyValue('filesystem', $filesystem);
            $callback();
        } finally {
            $reflection->setStaticPropertyValue('filesystem', $previousFilesystem);
        }
    }

    /**
     * Delete a runtime directory through Bootstrapper with a fake filesystem.
     */
    private function deleteRuntimeDirectoryWithFilesystem(Filesystem $filesystem): void
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $method = new ReflectionMethod(Bootstrapper::class, 'deleteRuntimeDirectory');
        $previousFilesystem = $reflection->getStaticPropertyValue('filesystem');

        $method->setAccessible(true);

        try {
            $reflection->setStaticPropertyValue('filesystem', $filesystem);
            $method->invoke(null, '/tmp/hypervel-runtime-copy');
        } finally {
            $reflection->setStaticPropertyValue('filesystem', $previousFilesystem);
        }
    }

    /**
     * Run a callback with isolated runtime-copy environment state.
     *
     * The stale sweep deletes same-PID copies that are not the active path.
     * Callers that replace the active path before creation must use process
     * isolation, and each block may create at most one runtime copy.
     */
    private function withRuntimeCopyEnvironment(string $token, bool $packageTester, callable $callback): void
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousServerPackageTester = $_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousEnvironmentPackageTester = $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? null;
        $previousProcessPackageTester = getenv('TESTBENCH_PACKAGE_TESTER');
        $previousRuntimePath = $reflection->getStaticPropertyValue('runtimePath');

        try {
            $this->setTestToken($token);

            if ($packageTester) {
                $this->setPackageTester();
            } else {
                $this->restorePackageTester(null, null, false);
            }

            $callback();
        } finally {
            $this->restoreTestToken($previousServerToken, $previousEnvironmentToken);
            $this->restorePackageTester($previousServerPackageTester, $previousEnvironmentPackageTester, $previousProcessPackageTester);
            $reflection->setStaticPropertyValue('runtimePath', $previousRuntimePath);
        }
    }

    /**
     * Create a runtime directory containing matching serve identity markers.
     *
     * @return array{string, int}
     */
    private function createServeIdentityRuntime(
        ?int $pid = null,
        string $startIdentity = 'start-identity',
        bool $writePidFile = true,
    ): array {
        $runtimePath = $this->temporaryDirectory('serve-identity');
        $pid ??= getmypid();

        mkdir($runtimePath . '/storage/framework', 0777, true);

        if ($writePidFile) {
            file_put_contents(
                $runtimePath . '/storage/framework/hypervel.pid',
                (string) $pid,
            );
        }

        file_put_contents(
            $runtimePath . '/.testbench-process',
            json_encode([
                'pid' => $pid,
                'started_at' => $startIdentity,
            ], JSON_THROW_ON_ERROR),
        );

        return [$runtimePath, $pid];
    }

    /**
     * Start a child process with Swoole's serve-master process title.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: int}
     */
    private function startTitledProcess(): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                <<<'PHP'
if (! cli_set_process_title('Testbench.Master')) {
    fwrite(STDOUT, "failed\n");
    exit(1);
}

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
sleep(30);
PHP,
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the titled child process.');
        }

        fclose($pipes[0]);
        $status = proc_get_status($process);

        if (fgets($pipes[1]) !== "ready\n" || ! $status['running']) {
            $this->stopProcess($process, $pipes, (int) $status['pid']);

            throw new RuntimeException('The child process could not apply its serve-master title.');
        }

        return [$process, $pipes, (int) $status['pid']];
    }

    /**
     * Stop a child process started by this test.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function stopProcess(mixed $process, array $pipes, int $pid): void
    {
        if ($pid > 0 && posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * Set an isolated test token for runtime copy paths.
     */
    private function setTestToken(string $token): void
    {
        $_SERVER['TEST_TOKEN'] = $token;
        $_ENV['TEST_TOKEN'] = $token;
    }

    /**
     * Set package tester mode for runtime copy behavior.
     */
    private function setPackageTester(): void
    {
        $_SERVER['TESTBENCH_PACKAGE_TESTER'] = '(true)';
        $_ENV['TESTBENCH_PACKAGE_TESTER'] = '(true)';
        putenv('TESTBENCH_PACKAGE_TESTER=(true)');
        Env::flushRepository();
    }

    /**
     * Restore the previous test token.
     */
    private function restoreTestToken(?string $serverToken, ?string $environmentToken): void
    {
        if ($serverToken === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $serverToken;
        }

        if ($environmentToken === null) {
            unset($_ENV['TEST_TOKEN']);
        } else {
            $_ENV['TEST_TOKEN'] = $environmentToken;
        }
    }

    /**
     * Restore package tester mode.
     */
    private function restorePackageTester(
        ?string $serverPackageTester,
        ?string $environmentPackageTester,
        string|false $processPackageTester
    ): void {
        if ($processPackageTester === false) {
            putenv('TESTBENCH_PACKAGE_TESTER');
        } else {
            putenv("TESTBENCH_PACKAGE_TESTER={$processPackageTester}");
        }

        if ($serverPackageTester === null) {
            unset($_SERVER['TESTBENCH_PACKAGE_TESTER']);
        } else {
            $_SERVER['TESTBENCH_PACKAGE_TESTER'] = $serverPackageTester;
        }

        if ($environmentPackageTester === null) {
            unset($_ENV['TESTBENCH_PACKAGE_TESTER']);
        } else {
            $_ENV['TESTBENCH_PACKAGE_TESTER'] = $environmentPackageTester;
        }

        Env::flushRepository();
    }

    /**
     * Delete a temporary directory.
     */
    private function deleteDirectory(?string $path): void
    {
        if ($path === null || ! is_dir($path)) {
            return;
        }

        (new Filesystem)->deleteDirectory($path);
    }
}

class BootstrapperIdentityProbe extends Bootstrapper
{
    protected static ?string $startIdentity = null;

    protected static bool $hasStartIdentityOverride = false;

    public static function setStartIdentity(?string $startIdentity): void
    {
        static::$startIdentity = $startIdentity;
        static::$hasStartIdentityOverride = true;
    }

    public static function resetStartIdentity(): void
    {
        static::$startIdentity = null;
        static::$hasStartIdentityOverride = false;
    }

    public static function matchesServeProcess(int $pid, string $runtimeDir): bool
    {
        return parent::matchesServeProcessIdentity($pid, $runtimeDir);
    }

    public static function isOrphanedServe(int $pid, string $runtimeDir): bool
    {
        return parent::isOrphanedServeProcess($pid, $runtimeDir);
    }

    public static function startIdentity(int $pid): ?string
    {
        return parent::processStartIdentity($pid);
    }

    protected static function processStartIdentity(int $pid): ?string
    {
        return static::$hasStartIdentityOverride
            ? static::$startIdentity
            : parent::processStartIdentity($pid);
    }
}

class RuntimeDirectoryStillPresentFilesystem extends Filesystem
{
    public int $deleteAttempts = 0;

    /**
     * Determine if the directory still exists.
     */
    public function isDirectory(string $directory): bool
    {
        return true;
    }

    /**
     * Simulate a filesystem failure that leaves the directory behind.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        ++$this->deleteAttempts;

        throw new UnexpectedValueException('runtime directory still present');
    }
}

class UnremovableRuntimePathFilesystem extends Filesystem
{
    public bool $copyAttempted = false;

    public function __construct(protected string $unremovablePath)
    {
    }

    /**
     * Leave the configured runtime path in place.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        return $directory === $this->unremovablePath
            ? false
            : parent::deleteDirectory($directory, $preserve);
    }

    /**
     * Record an attempt to publish the runtime copy.
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        $this->copyAttempted = true;

        return parent::copyDirectory($directory, $destination, $options);
    }
}

class FailedRuntimeCopyFilesystem extends Filesystem
{
    public ?string $runtimePath = null;

    /**
     * Create a partial destination before reporting copy failure.
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        $this->runtimePath = $destination;
        file_put_contents($destination . '/partial.txt', 'partial');

        return false;
    }
}

class FailedRuntimeRootFilesystem extends Filesystem
{
    public bool $copyAttempted = false;

    /**
     * Simulate runtime-root creation failure.
     */
    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false): bool
    {
        return false;
    }

    /**
     * Record an unexpected directory-copy attempt.
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        $this->copyAttempted = true;

        return false;
    }
}

class FailedRuntimeOverlayFilesystem extends Filesystem
{
    public int $makeDirectoryAttempts = 0;

    /**
     * Record an unexpected attempt to recreate the active runtime root.
     */
    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false): bool
    {
        ++$this->makeDirectoryAttempts;

        return parent::makeDirectory($path, $mode, $recursive, $force);
    }

    /**
     * Simulate an overlay failure on the active runtime.
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        return false;
    }
}

class FailedEnvironmentCopyFilesystem extends Filesystem
{
    public ?string $runtimePath = null;

    /**
     * Capture the runtime path while copying the skeleton.
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        $this->runtimePath = $destination;

        return parent::copyDirectory($directory, $destination, $options);
    }

    /**
     * Fail only the environment publication copy.
     */
    public function copy(string $path, string $target): bool
    {
        return basename($target) !== '.env' && parent::copy($path, $target);
    }
}

class FailedRuntimeMarkerFilesystem extends Filesystem
{
    public ?string $runtimePath = null;

    public function __construct(private RuntimeException $failure)
    {
    }

    /**
     * Capture the runtime destination while copying it normally.
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        $this->runtimePath = $destination;

        return parent::copyDirectory($directory, $destination, $options);
    }

    /**
     * Simulate process-marker creation failure.
     */
    public function replace(string $path, string $content, ?int $mode = null): void
    {
        throw $this->failure;
    }
}
