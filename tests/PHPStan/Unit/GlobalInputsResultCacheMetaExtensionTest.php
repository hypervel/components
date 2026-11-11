<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\GlobalInputsResultCacheMetaExtension;
use Hypervel\PHPStan\HypervelStubFilesExtension;
use Hypervel\PHPStan\Support\EnvironmentSnapshot;
use Hypervel\Tests\PHPStan\TestCase;
use ReflectionProperty;

class GlobalInputsResultCacheMetaExtensionTest extends TestCase
{
    public function testHashCoversTheStubListAndTheBootIdentity(): void
    {
        $captured = new ReflectionProperty(EnvironmentSnapshot::class, 'captured');
        $original = $captured->getValue();
        $hash = static function (array $stubFiles, array $variables) use ($captured): string {
            $captured->setValue(null, ['server' => [], 'env' => [], 'process' => $variables]);

            return (new GlobalInputsResultCacheMetaExtension(new class($stubFiles) extends HypervelStubFilesExtension {
                public function __construct(private array $files)
                {
                }

                public function getFiles(): array
                {
                    return $this->files;
                }
            }))->getHash();
        };

        try {
            $base = $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a']);

            $this->assertSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'b']));
            $this->assertNotSame($base, $hash(['/stubs/Model.stub', '/stubs/Builder.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a']));
            $this->assertNotSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'staging', 'UNRELATED' => 'a']));
            $this->assertNotSame($base, $hash(['/stubs/Model.stub'], ['APP_ENV' => 'local', 'UNRELATED' => 'a', 'TESTBENCH_WORKING_PATH' => '/package']));
        } finally {
            $captured->setValue(null, $original);
        }
    }
}
