<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\FileBasedMaintenanceMode;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
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
}
