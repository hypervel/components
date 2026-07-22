<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\FileBasedMaintenanceMode;
use Hypervel\Testbench\TestCase;
use JsonException;
use Mockery as m;
use RuntimeException;

class FoundationFileBasedMaintenanceModeTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(storage_path('framework/down'));

        parent::tearDown();
    }

    public function testActiveReturnsFalseWhenFileDoesNotExist()
    {
        $mode = new FileBasedMaintenanceMode;

        $this->assertFalse($mode->active());
    }

    public function testActivateWritesJsonToCorrectPath()
    {
        $mode = new FileBasedMaintenanceMode;

        $mode->activate(['status' => 503, 'retry' => 60]);

        $this->assertFileExists(storage_path('framework/down'));

        $data = json_decode(file_get_contents(storage_path('framework/down')), true);
        $this->assertSame(503, $data['status']);
        $this->assertSame(60, $data['retry']);
    }

    public function testActiveReturnsTrueWhenFileExists()
    {
        $mode = new FileBasedMaintenanceMode;

        $mode->activate(['status' => 503]);

        $this->assertTrue($mode->active());
    }

    public function testDataReturnsDecodedPayload()
    {
        $mode = new FileBasedMaintenanceMode;

        $mode->activate(['status' => 503, 'secret' => 'abc123', 'retry' => null]);

        $data = $mode->data();

        $this->assertSame(503, $data['status']);
        $this->assertSame('abc123', $data['secret']);
        $this->assertNull($data['retry']);
    }

    public function testDeactivateDeletesFile()
    {
        $mode = new FileBasedMaintenanceMode;

        $mode->activate(['status' => 503]);
        $this->assertTrue($mode->active());

        $mode->deactivate();
        $this->assertFalse($mode->active());
        $this->assertFileDoesNotExist(storage_path('framework/down'));
    }

    public function testDeactivateDoesNothingWhenNotActive()
    {
        $mode = new FileBasedMaintenanceMode;

        $mode->deactivate();

        $this->assertFalse($mode->active());
    }

    public function testActivatePreservesPreviousPayloadWhenPublicationFails(): void
    {
        $path = storage_path('framework/down');
        file_put_contents($path, '{"status":503}');

        $files = m::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('replace')
            ->once()
            ->with($path, "{\n    \"status\": 418\n}")
            ->andThrow($exception = new RuntimeException('publication failed'));

        try {
            (new FileBasedMaintenanceMode($files))->activate(['status' => 418]);

            self::fail('Expected the publication failure to be rethrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertSame('{"status":503}', file_get_contents($path));
    }

    public function testActivateRejectsPayloadThatCannotBeEncodedWithoutReplacingExistingState(): void
    {
        $mode = new FileBasedMaintenanceMode;
        $mode->activate(['status' => 503]);

        $this->expectException(JsonException::class);

        try {
            $mode->activate(['retry' => INF]);
        } finally {
            $this->assertSame(['status' => 503], $mode->data());
        }
    }

    public function testDataRejectsMalformedJson(): void
    {
        file_put_contents(storage_path('framework/down'), '{');

        $this->expectException(JsonException::class);

        (new FileBasedMaintenanceMode)->data();
    }

    public function testDataRejectsNonArrayJson(): void
    {
        file_put_contents(storage_path('framework/down'), 'null');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The maintenance mode file does not contain a valid payload.');

        (new FileBasedMaintenanceMode)->data();
    }

    public function testDeactivateFailsWhenDeleteReturnsFalseAndFileRemains(): void
    {
        $path = storage_path('framework/down');
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->twice()->with($path)->andReturnTrue();
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to remove the maintenance mode file [{$path}].");

        (new FileBasedMaintenanceMode($files))->deactivate();
    }

    public function testDeactivateSucceedsWhenAnotherOwnerRemovesTheFile(): void
    {
        $path = storage_path('framework/down');
        $files = m::mock(Filesystem::class);
        $files->shouldReceive('exists')->twice()->with($path)->andReturn(true, false);
        $files->shouldReceive('delete')->once()->with($path)->andReturnFalse();

        (new FileBasedMaintenanceMode($files))->deactivate();

        $this->assertTrue(true);
    }
}
