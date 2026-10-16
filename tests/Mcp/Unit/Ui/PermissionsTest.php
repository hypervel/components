<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Ui;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Server\Ui\Enums\Permission;
use Hypervel\Mcp\Server\Ui\Permissions;
use Hypervel\Tests\TestCase;

class PermissionsTest extends TestCase
{
    public function testSerializesAsEmptyWhenNoPermissionsSet(): void
    {
        $this->assertSame([], Permissions::make()->toArray());
    }

    public function testSerializesCameraPermissionAsEmptyObject(): void
    {
        $permissions = Permissions::make()->camera();

        $array = $permissions->toArray();

        $this->assertArrayHasKey('camera', $array);
        $this->assertSame('{}', json_encode($array['camera']));
    }

    public function testSerializesMultiplePermissions(): void
    {
        $permissions = Permissions::make()
            ->camera()
            ->microphone()
            ->geolocation()
            ->clipboardWrite();

        $array = $permissions->toArray();

        $this->assertArrayHasKey('camera', $array);
        $this->assertArrayHasKey('microphone', $array);
        $this->assertArrayHasKey('geolocation', $array);
        $this->assertArrayHasKey('clipboardWrite', $array);
    }

    public function testSupportsAllowWithMultipleEnums(): void
    {
        $permissions = Permissions::make()->allow(Permission::Camera, Permission::ClipboardWrite);

        $array = $permissions->toArray();

        $this->assertArrayHasKey('camera', $array);
        $this->assertArrayHasKey('clipboardWrite', $array);
        $this->assertArrayNotHasKey('microphone', $array);
        $this->assertArrayNotHasKey('geolocation', $array);
    }

    public function testImplementsArrayable(): void
    {
        $this->assertInstanceOf(Arrayable::class, new Permissions);
    }

    public function testAllowsPermissionsViaEnum(): void
    {
        $permissions = Permissions::make()->allow(
            Permission::Camera,
            Permission::ClipboardWrite,
        );

        $array = $permissions->toArray();

        $this->assertArrayHasKey('camera', $array);
        $this->assertArrayHasKey('clipboardWrite', $array);
        $this->assertArrayNotHasKey('microphone', $array);
        $this->assertArrayNotHasKey('geolocation', $array);
    }
}
