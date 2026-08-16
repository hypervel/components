<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\PasskeysTest;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
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

    public function testConfigDefaultsRemainAligned(): void
    {
        $this->unsetEnvironmentValue('PASSKEYS_TIMEOUT');

        $config = require dirname(__DIR__, 3) . '/src/passkeys/config/passkeys.php';

        $this->assertSame(Passkeys::DEFAULT_TIMEOUT, $config['timeout']);
        $this->assertSame(Passkeys::DEFAULT_THROTTLE, $config['throttle']);
    }

    public function testOmittedTimeoutAndRedirectUseTheirDefaults(): void
    {
        $config = config()->array('passkeys');
        unset($config['timeout'], $config['redirect']);
        config()->set('passkeys', $config);

        $this->assertSame(Passkeys::DEFAULT_TIMEOUT, Passkeys::timeout());
        $this->assertSame('/', Passkeys::redirectTo(Request::create('/')));
    }

    public function testItReturnsTheConfiguredUserHandleSecret(): void
    {
        config(['passkeys.user_handle_secret' => 'configured-secret']);

        $this->assertSame('configured-secret', Passkeys::userHandleSecret());
    }

    public function testConfigDefaultsTheUserHandleSecretToTheApplicationKey(): void
    {
        config(['app.key' => 'application-key']);

        $config = require dirname(__DIR__, 3) . '/src/passkeys/config/passkeys.php';

        $this->assertSame('application-key', $config['user_handle_secret']);
    }

    public function testConfigUsesExplicitPasskeyValuesWhenApplicationUrlAndKeyAreNull(): void
    {
        config([
            'app.key' => null,
            'app.url' => null,
        ]);
        $this->setEnvironmentValue('PASSKEYS_RELYING_PARTY_ID', 'example.com');
        $this->setEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS', 'https://example.com');
        $this->setEnvironmentValue('PASSKEYS_USER_HANDLE_SECRET', 'explicit-secret');

        try {
            $config = require dirname(__DIR__, 3) . '/src/passkeys/config/passkeys.php';

            $this->assertSame('example.com', $config['relying_party_id']);
            $this->assertSame(['https://example.com'], $config['allowed_origins']);
            $this->assertSame('explicit-secret', $config['user_handle_secret']);
        } finally {
            $this->unsetEnvironmentValue('PASSKEYS_RELYING_PARTY_ID');
            $this->unsetEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS');
            $this->unsetEnvironmentValue('PASSKEYS_USER_HANDLE_SECRET');
        }
    }

    public function testNullDerivedPasskeyDefaultsFailAtTheDomainBoundary(): void
    {
        config([
            'app.key' => null,
            'app.url' => null,
        ]);
        $this->unsetEnvironmentValue('PASSKEYS_RELYING_PARTY_ID');
        $this->unsetEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS');
        $this->unsetEnvironmentValue('PASSKEYS_USER_HANDLE_SECRET');

        $config = require dirname(__DIR__, 3) . '/src/passkeys/config/passkeys.php';

        config([
            'passkeys.relying_party_id' => $config['relying_party_id'],
            'passkeys.allowed_origins' => $config['allowed_origins'],
            'passkeys.user_handle_secret' => $config['user_handle_secret'],
        ]);

        $this->assertNull($config['relying_party_id']);
        $this->assertSame([], $config['allowed_origins']);
        $this->assertNull($config['user_handle_secret']);
        $this->assertThrows(
            fn () => Passkeys::relyingPartyId(),
            RuntimeException::class,
            'Passkey relying party ID must not be empty.',
        );
        $this->assertThrows(
            fn () => Passkeys::allowedOrigins(),
            RuntimeException::class,
            'At least one passkey allowed origin must be configured.',
        );
        $this->assertThrows(
            fn () => Passkeys::userHandleSecret(),
            RuntimeException::class,
            'Passkey user handle secret must not be empty.',
        );
    }

    public function testItThrowsWhenTheUserHandleSecretIsEmpty(): void
    {
        config(['passkeys.user_handle_secret' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passkey user handle secret must not be empty.');

        Passkeys::userHandleSecret();
    }

    public function testItReturnsTheConfiguredRelyingPartyId(): void
    {
        config(['passkeys.relying_party_id' => 'configured.example.com']);

        $this->assertSame('configured.example.com', Passkeys::relyingPartyId());
    }

    public function testRequestAwareRelyingPartyIdOverridesConfigWhenRequestExists(): void
    {
        config(['passkeys.relying_party_id' => 'configured.example.com']);
        RequestContext::set(Request::create('https://dynamic.example.com/passkeys/login/options'));

        Passkeys::resolveRelyingPartyIdUsing(
            static fn (Request $request): string => $request->getHost(),
        );

        $this->assertSame('dynamic.example.com', Passkeys::relyingPartyId());
    }

    public function testRequestAwareRelyingPartyIdFallsBackToConfigWithoutRequest(): void
    {
        config(['passkeys.relying_party_id' => 'configured.example.com']);

        Passkeys::resolveRelyingPartyIdUsing(
            static fn (): string => 'dynamic.example.com',
        );

        $this->assertSame('configured.example.com', Passkeys::relyingPartyId());
    }

    public function testItThrowsWhenRelyingPartyIdIsEmpty(): void
    {
        config(['passkeys.relying_party_id' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passkey relying party ID must not be empty.');

        Passkeys::relyingPartyId();
    }

    public function testItThrowsWhenRequestAwareRelyingPartyIdIsEmpty(): void
    {
        config(['passkeys.relying_party_id' => 'configured.example.com']);
        RequestContext::set(Request::create('https://dynamic.example.com/passkeys/login/options'));

        Passkeys::resolveRelyingPartyIdUsing(
            static fn (): string => '',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passkey relying party ID resolver returned no value for host [dynamic.example.com].');

        Passkeys::relyingPartyId();
    }

    public function testItReturnsTheConfiguredAllowedOrigins(): void
    {
        config(['passkeys.allowed_origins' => ['https://example.com', 'https://app.example.com']]);

        $this->assertSame(['https://example.com', 'https://app.example.com'], Passkeys::allowedOrigins());
    }

    public function testRequestAwareAllowedOriginsOverrideConfigWhenRequestExists(): void
    {
        config(['passkeys.allowed_origins' => ['https://configured.example.com']]);
        RequestContext::set(Request::create('https://dynamic.example.com/passkeys/login/options'));

        Passkeys::resolveAllowedOriginsUsing(
            static fn (Request $request): array => ['https://' . $request->getHost()],
        );

        $this->assertSame(['https://dynamic.example.com'], Passkeys::allowedOrigins());
    }

    public function testRequestAwareAllowedOriginsFallBackToConfigWithoutRequest(): void
    {
        config(['passkeys.allowed_origins' => ['https://configured.example.com']]);

        Passkeys::resolveAllowedOriginsUsing(
            static fn (): array => ['https://dynamic.example.com'],
        );

        $this->assertSame(['https://configured.example.com'], Passkeys::allowedOrigins());
    }

    public function testConfigReadsAllowedOriginsFromEnvironment(): void
    {
        $this->setEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS', 'https://example.com, https://www.example.com');

        try {
            $config = require dirname(__DIR__, 3) . '/src/passkeys/config/passkeys.php';

            $this->assertSame(['https://example.com', 'https://www.example.com'], $config['allowed_origins']);
        } finally {
            $this->unsetEnvironmentValue('PASSKEYS_ALLOWED_ORIGINS');
        }
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

    public function testItThrowsWhenRequestAwareAllowedOriginsAreEmpty(): void
    {
        config(['passkeys.allowed_origins' => ['https://configured.example.com']]);
        RequestContext::set(Request::create('https://dynamic.example.com/passkeys/login/options'));

        Passkeys::resolveAllowedOriginsUsing(
            static fn (): array => ['', null],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passkey allowed origins resolver returned no values for host [dynamic.example.com].');

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
