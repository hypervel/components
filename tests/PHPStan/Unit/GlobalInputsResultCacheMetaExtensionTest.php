<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\GlobalInputsResultCacheMetaExtension;
use Hypervel\PHPStan\HypervelStubFilesExtension;
use Hypervel\PHPStan\Support\EnvironmentSnapshot;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\PHPStan\TestCase;
use PHPStan\File\FileHelper;
use ReflectionProperty;

use function mkdir;

class GlobalInputsResultCacheMetaExtensionTest extends TestCase
{
    public function testHashCoversTheStubListTheConfiguredDirectoriesAndTheBootIdentity(): void
    {
        $root = ParallelTesting::tempDir('PHPStanGlobalInputs');
        (new Filesystem)->deleteDirectory($root);
        mkdir($root . '/modules/billing/config', 0777, true);

        $captured = new ReflectionProperty(EnvironmentSnapshot::class, 'captured');
        $original = $captured->getValue();
        $hash = static function (array $stubFiles, array $variables) use ($captured, $root): string {
            $captured->setValue(null, ['server' => [], 'env' => [], 'process' => $variables]);

            return (new GlobalInputsResultCacheMetaExtension(new class($stubFiles) extends HypervelStubFilesExtension {
                public function __construct(private array $files)
                {
                }

                public function getFiles(): array
                {
                    return $this->files;
                }
            }, new FileHelper($root), ['modules/*/config'], ['modules/*/migrations'], ['modules/*/schema']))->getHash();
        };

        try {
            $base = $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a']);

            $this->assertSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'b']));
            $this->assertNotSame($base, $hash(['/stubs/Model.stub', '/stubs/Builder.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a']));
            $this->assertNotSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'staging', 'UNRELATED' => 'a']));
            $this->assertNotSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a', 'TESTBENCH_WORKING_PATH' => '/package']));

            // A directory a configured glob starts or stops matching changes which config, migration or schema files load.
            foreach (['config', 'migrations', 'schema'] as $directory) {
                mkdir($root . '/modules/shipping/' . $directory, 0777, true);
                $this->assertNotSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a']));
                (new Filesystem)->deleteDirectory($root . '/modules/shipping');
                $this->assertSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a']));
            }
        } finally {
            $captured->setValue(null, $original);
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
