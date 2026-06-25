<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Context\CoroutineContext;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Models\Permission as HypervelPermission;
use Hypervel\Permission\Models\Role as HypervelRole;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Tests\Permission\Fixtures\Models\Permission as TestPermission;
use Hypervel\Tests\Permission\Fixtures\Models\Role as TestRole;
use Hypervel\Tests\Permission\TestCase;

class PermissionRegistrarTest extends TestCase
{
    public function testItCanClearLoadedPermissionsCollection(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $registrar->getPermissions();

        $this->assertTrue(CoroutineContext::has(PermissionRegistrar::PERMISSION_CATALOG_CONTEXT_KEY));

        $registrar->clearPermissionsCollection();

        $this->assertFalse(CoroutineContext::has(PermissionRegistrar::PERMISSION_CATALOG_CONTEXT_KEY));
    }

    public function testItCanCheckUids(): void
    {
        $uids = [
            // UUIDs
            '00000000-0000-0000-0000-000000000000',
            '9be37b52-e1fa-4e86-b65f-cbfcbedde838',
            'fc458041-fb21-4eea-a04b-b55c87a7224a',
            '78144b52-a889-11ed-afa1-0242ac120002',
            '78144f4e-a889-11ed-afa1-0242ac120002',
            // GUIDs
            '4b8590bb-90a2-4f38-8dc9-70e663a5b0e5',
            'A98C5A1E-A742-4808-96FA-6F409E799937',
            '1f01164a-98e9-4246-93ec-7941aefb1da6',
            '91b73d20-89e6-46b0-b39b-632706cc3ed7',
            '0df4a5b8-7c2e-484f-ad1d-787d1b83aacc',
            // ULIDs
            '01GRVB3DREB63KNN4G2QVV99DF',
            '01GRVB3DRECY317SJCJ6DMTFCA',
            '01GRVB3DREGGPBXNH1M24GX1DS',
            '01GRVB3DRESRM2K9AVQSW1JCKA',
            '01GRVB3DRES5CQ31PB24MP4CSV',
        ];

        $notUids = [
            '9be37b52-e1fa',
            '9be37b52-e1fa-4e86',
            '9be37b52-e1fa-4e86-b65f',
            '01GRVB3DREB63KNN4G2',
            'TEST STRING',
            '00-00-00-00-00-00',
            '91GRVB3DRES5CQ31PB24MP4CSV',
        ];

        foreach ($uids as $uid) {
            $this->assertTrue(PermissionRegistrar::isUid($uid));
        }

        foreach ($notUids as $notUid) {
            $this->assertFalse(PermissionRegistrar::isUid($notUid));
        }
    }

    public function testItCanGetPermissionClass(): void
    {
        $this->assertSame(HypervelPermission::class, $this->app->make(PermissionRegistrar::class)->getPermissionClass());
        $this->assertInstanceOf(HypervelPermission::class, $this->app->make(PermissionContract::class));
    }

    public function testItCanChangePermissionClass(): void
    {
        $this->assertSame(HypervelPermission::class, $this->app->make('config')->get('permission.models.permission'));
        $this->assertSame(HypervelPermission::class, $this->app->make(PermissionRegistrar::class)->getPermissionClass());
        $this->assertInstanceOf(HypervelPermission::class, $this->app->make(PermissionContract::class));

        $this->app->make(PermissionRegistrar::class)->setPermissionClass(TestPermission::class);

        $this->assertSame(HypervelPermission::class, $this->app->make('config')->get('permission.models.permission'));
        $this->assertSame(TestPermission::class, $this->app->make(PermissionRegistrar::class)->getPermissionClass());
        $this->assertInstanceOf(TestPermission::class, $this->app->make(PermissionContract::class));
    }

    public function testItCanGetRoleClass(): void
    {
        $this->assertSame(HypervelRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(HypervelRole::class, $this->app->make(RoleContract::class));
    }

    public function testItCanChangeRoleClass(): void
    {
        $this->assertSame(HypervelRole::class, $this->app->make('config')->get('permission.models.role'));
        $this->assertSame(HypervelRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(HypervelRole::class, $this->app->make(RoleContract::class));

        $this->app->make(PermissionRegistrar::class)->setRoleClass(TestRole::class);

        $this->assertSame(HypervelRole::class, $this->app->make('config')->get('permission.models.role'));
        $this->assertSame(TestRole::class, $this->app->make(PermissionRegistrar::class)->getRoleClass());
        $this->assertInstanceOf(TestRole::class, $this->app->make(RoleContract::class));
    }

    public function testItCanChangeTeamId(): void
    {
        $teamId = '00000000-0000-0000-0000-000000000000';

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($teamId);

        $this->assertSame($teamId, $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());
    }
}
