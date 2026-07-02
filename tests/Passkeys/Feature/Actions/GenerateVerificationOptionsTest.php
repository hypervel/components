<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Passkeys\Actions\GenerateVerificationOptions;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

class GenerateVerificationOptionsTest extends TestCase
{
    public function testItGeneratesVerificationOptions(): void
    {
        $options = app(GenerateVerificationOptions::class)();

        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $options);
    }

    public function testItUsesEmptyAllowCredentialsForDiscoverableFlow(): void
    {
        $options = app(GenerateVerificationOptions::class)();

        $this->assertSame('localhost', $options->rpId);
        $this->assertSame([], $options->allowCredentials);
    }

    public function testItUsesOnlyTheAuthenticatedUserPasskeysForAllowCredentials(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $credentialIdOne = random_bytes(16);
        $credentialIdTwo = random_bytes(16);

        $user->passkeys()->create([
            'name' => 'MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialIdOne),
            'credential' => [],
        ]);

        $user->passkeys()->create([
            'name' => 'iPhone',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialIdTwo),
            'credential' => [],
        ]);

        $options = app(GenerateVerificationOptions::class)($user);

        $this->assertCount(2, $options->allowCredentials);
        $this->assertSame(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, $options->allowCredentials[0]->type);
        $this->assertSame(
            collect([
                Base64UrlSafe::encodeUnpadded($credentialIdOne),
                Base64UrlSafe::encodeUnpadded($credentialIdTwo),
            ])->sort()->values()->all(),
            collect($options->allowCredentials)
                ->map(static fn (PublicKeyCredentialDescriptor $credential): string => Base64UrlSafe::encodeUnpadded($credential->id))
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$credentialIdOne, $credentialIdTwo],
            collect($options->allowCredentials)
                ->map(static fn (PublicKeyCredentialDescriptor $credential): string => $credential->id)
                ->all(),
        );
    }
}
