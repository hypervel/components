<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Http\Request;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;

class PasskeysStaticStateTest extends TestCase
{
    public function testFlushStateResetsRouteRegistrationAndRedirectCallback(): void
    {
        config(['passkeys.redirect' => '/account']);

        Passkeys::ignoreRoutes();
        Passkeys::redirectUsing(static fn (Request $request): string => '/tenant-home');

        $this->assertFalse(Passkeys::shouldRegisterRoutes());
        $this->assertSame('/tenant-home', Passkeys::redirectTo(Request::create('/')));

        Passkeys::flushState();

        $this->assertTrue(Passkeys::shouldRegisterRoutes());
        $this->assertSame('/account', Passkeys::redirectTo(Request::create('/')));
    }

    public function testFlushStateResetsPasskeyModel(): void
    {
        Passkeys::usePasskeyModel(CustomPasskeyModel::class);

        $this->assertSame(CustomPasskeyModel::class, Passkeys::passkeyModel());

        Passkeys::flushState();

        $this->assertSame(Passkey::class, Passkeys::passkeyModel());
    }
}

class CustomPasskeyModel extends Passkey
{
}
