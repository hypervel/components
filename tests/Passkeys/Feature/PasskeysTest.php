<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\PasskeysTest;

use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;
use RuntimeException;

class PasskeysTest extends TestCase
{
    public function testItReturnsTheDefaultPasskeyModel(): void
    {
        $this->assertSame(Passkey::class, Passkeys::passkeyModel());
    }

    public function testItCanSetACustomPasskeyModel(): void
    {
        Passkeys::usePasskeyModel(CustomPasskey::class);

        $this->assertSame(CustomPasskey::class, Passkeys::passkeyModel());
    }

    public function testItReturnsTheConfiguredTimeout(): void
    {
        config(['passkeys.timeout' => 30000]);

        $this->assertSame(30000, Passkeys::timeout());
    }

    public function testItReturnsTheConfiguredAllowedOrigins(): void
    {
        config(['passkeys.allowed_origins' => ['https://example.com', 'https://app.example.com']]);

        $this->assertSame(['https://example.com', 'https://app.example.com'], Passkeys::allowedOrigins());
    }

    public function testItFiltersOutEmptyAllowedOriginEntries(): void
    {
        config(['passkeys.allowed_origins' => ['https://example.com', '', null]]);

        $this->assertSame(['https://example.com'], Passkeys::allowedOrigins());
    }

    public function testItThrowsWhenNoAllowedOriginsAreConfigured(): void
    {
        config(['passkeys.allowed_origins' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('At least one passkey allowed origin must be configured.');

        Passkeys::allowedOrigins();
    }

    public function testItSupportsCustomSignInAuthorizationCallbacks(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'My Passkey',
            'credential_id' => 'test-credential-id',
            'credential' => [],
        ]);

        Passkeys::authorizeLoginUsing(static fn (): bool => false);

        $this->assertFalse(Passkeys::allowsLogin(request(), $passkey));
    }
}

class CustomPasskey extends Passkey
{
}
