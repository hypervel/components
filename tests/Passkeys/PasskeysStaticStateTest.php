<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Context\RequestContext;
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

    public function testFlushStateResetsRequestAwareWebAuthnCallbacks(): void
    {
        config([
            'passkeys.relying_party_id' => 'configured.example.com',
            'passkeys.allowed_origins' => ['https://configured.example.com'],
        ]);
        RequestContext::set(Request::create('https://dynamic.example.com/passkeys/login/options'));

        Passkeys::relyingPartyIdUsing(static fn (): string => 'dynamic.example.com');
        Passkeys::allowedOriginsUsing(static fn (): array => ['https://dynamic.example.com']);

        $this->assertSame('dynamic.example.com', Passkeys::relyingPartyId());
        $this->assertSame(['https://dynamic.example.com'], Passkeys::allowedOrigins());

        Passkeys::flushState();

        $this->assertSame('configured.example.com', Passkeys::relyingPartyId());
        $this->assertSame(['https://configured.example.com'], Passkeys::allowedOrigins());
    }
}

class CustomPasskeyModel extends Passkey
{
}
