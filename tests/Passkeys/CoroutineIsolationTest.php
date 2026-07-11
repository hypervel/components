<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    use WebAuthnFixtures;

    public function testRequestAwareAllowedOriginsAreIsolatedBetweenCoroutines(): void
    {
        Passkeys::resolveAllowedOriginsUsing(
            static fn (Request $request): array => ['https://' . $request->getHost()],
        );

        $results = parallel([
            'first' => function (): string {
                RequestContext::set(Request::create('https://first.example.com/passkeys/login/options'));
                usleep(5000);

                return $this->verifyAssertionForHost('first.example.com');
            },
            'second' => function (): string {
                RequestContext::set(Request::create('https://second.example.com/passkeys/login/options'));
                usleep(5000);

                return $this->verifyAssertionForHost('second.example.com');
            },
        ]);

        $this->assertSame('first.example.com', $results['first']);
        $this->assertSame('second.example.com', $results['second']);
    }

    /**
     * Verify a signed assertion for a host.
     */
    private function verifyAssertionForHost(string $host): string
    {
        $challenge = random_bytes(32);
        $source = $this->createCredentialSource('user-handle');

        $verified = WebAuthn::assertionValidator()->check(
            credentialRecord: $source,
            authenticatorAssertionResponse: $this->createSignedAssertionResponse(
                challenge: $challenge,
                origin: 'https://' . $host,
                signCount: 1,
                rpId: $host,
            ),
            publicKeyCredentialRequestOptions: PublicKeyCredentialRequestOptions::create(
                challenge: $challenge,
                rpId: $host,
            ),
            host: $host,
            userHandle: $source->userHandle,
        );

        $this->assertInstanceOf(CredentialRecord::class, $verified);

        return $host;
    }
}
