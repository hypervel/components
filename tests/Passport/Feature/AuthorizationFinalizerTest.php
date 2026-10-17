<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Http\Request;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\AuthorizationFinalizer;
use Hypervel\Passport\Bridge\Client as ClientEntity;
use Hypervel\Passport\Bridge\DeviceCodeRepository;
use Hypervel\Passport\Bridge\Scope;
use Hypervel\Passport\Bridge\User as UserEntity;
use Hypervel\Passport\Consent;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\Enums\AuthorizationApproval;
use Hypervel\Passport\Events\AuthorizationApproved;
use Hypervel\Passport\ServerFactory;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passport\Fixtures\User;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class AuthorizationFinalizerTest extends PassportTestCase
{
    #[TestWith([[], ['read', 'write']])]
    #[TestWith([['scopes' => ['read']], ['read']])]
    #[TestWith([['scopes' => []], []])]
    #[TestWith([['scopes' => null], []])]
    #[TestWith([['scopes' => ''], []])]
    public function testCodeApprovalPersistsSelectedScopesAndProtectedIdentity(array $input, array $expected): void
    {
        [$authorization, $pending, $user] = $this->authorization();
        $observed = null;
        Event::listen(AuthorizationApproved::class, static function (AuthorizationApproved $event) use (&$observed): void {
            $observed = [$event->approval, $event->scopes];
            $event->attributes['provider'] = 'other';
            $event->attributes['consent_id'] = 'untrusted';
        });

        $result = $this->app->make(AuthorizationFinalizer::class)->approve(
            Request::create('/oauth/authorize/consent', 'POST', $input),
            $authorization,
            $pending,
            $user,
            AuthorizationApproval::Prompt,
            fn () => $this->app->make(ServerFactory::class)->authorizationServer()
                ->completeAuthorizationRequest($authorization, new Response),
        );

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame([AuthorizationApproval::Prompt, $expected], $observed);
        $consent = Consent::sole();
        $code = AuthCode::sole();
        $this->assertSame($expected, $consent->scopes);
        $this->assertSame($expected, $code->scopes);
        $this->assertSame('users', $code->provider);
        $this->assertSame($consent->id, $code->consent_id);
    }

    #[TestWith([false])]
    #[TestWith([null])]
    #[TestWith([''])]
    public function testDeviceApprovalPersistsSelectedScopesAndPermissionChoices(bool|string|null $remember): void
    {
        [$authorization, $pending, $user] = $this->authorization();
        $code = DeviceCode::create([
            'id' => 'device', 'client_id' => $authorization->getClient()->getIdentifier(),
            'user_code' => 'ABCDEFGH', 'scopes' => ['read', 'write'], 'revoked' => false,
            'expires_at' => Date::now()->addMinutes(10),
        ]);
        $entity = $this->app->make(DeviceCodeRepository::class)->getDeviceCodeEntityByDeviceCode($code->id);
        $pending['grant_type'] = 'urn:ietf:params:oauth:grant-type:device_code';
        $pending['permissions'] = ['profile.claims' => ['name', 'email']];
        $selected = ['scopes' => ['read'], 'permissions' => ['profile.claims' => ['name']], 'remember' => $remember];
        $observed = null;
        Event::listen(AuthorizationApproved::class, static function (AuthorizationApproved $event) use (&$observed): void {
            $observed = $event->permissions;
        });

        $this->app->make(AuthorizationFinalizer::class)->approve(
            Request::create('/oauth/device/authorize', 'POST', $selected),
            $entity,
            $pending,
            $user,
            AuthorizationApproval::Prompt,
            fn () => $this->app->make(ServerFactory::class)->authorizationServer()
                ->completeDeviceAuthorizationRequest($code->id, (string) $user->id, true),
        );

        $consent = Consent::sole();
        $this->assertSame($selected['permissions'], $observed);
        $this->assertSame($selected['permissions'], $consent->permissions);
        $this->assertFalse($consent->isRemembered());
        $this->assertSame(['read'], $code->fresh()->scopes);
        $this->assertSame($consent->id, $code->fresh()->consent_id);
        $this->assertNotNull($code->fresh()->user_approved_at);
    }

    #[TestWith([['scopes' => ['admin']]])]
    #[TestWith([['scopes' => 'read']])]
    #[TestWith([['permissions' => ['unknown' => ['name']]]])]
    #[TestWith([['permissions' => ['profile.claims' => ['address']]]])]
    #[TestWith([['remember' => ['yes']]])]
    #[TestWith([['remember' => 'invalid']])]
    public function testInjectedChoicesCannotCreateConsentOrCredentials(array $input): void
    {
        [$authorization, $pending, $user] = $this->authorization();
        $pending['permissions'] = ['profile.claims' => ['name']];

        try {
            $this->app->make(AuthorizationFinalizer::class)->approve(
                Request::create('/', 'POST', $input),
                $authorization,
                $pending,
                $user,
                AuthorizationApproval::Prompt,
                fn () => $this->fail('Invalid approval must not complete.'),
            );
            $this->fail('Invalid approval must be rejected.');
        } catch (OAuthServerException $exception) {
            $this->assertSame('invalid_request', $exception->getErrorType());
        }

        $this->assertSame(0, Consent::count());
        $this->assertSame(0, AuthCode::count());
    }

    #[TestWith(['creating'])]
    #[TestWith(['updating'])]
    public function testCanceledConsentPersistenceCannotIssueCredentials(string $event): void
    {
        [$authorization, $pending, $user] = $this->authorization();
        $consent = $event === 'updating' ? Consent::factory()->create([
            'client_id' => $authorization->getClient()->getIdentifier(), 'user_id' => $user->id,
            'scopes' => ['other'],
        ]) : null;
        $original = $consent?->fresh()->getRawOriginal();
        Consent::{$event}(static fn (): bool => false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Consent persistence was canceled.');

        try {
            $this->app->make(AuthorizationFinalizer::class)->approve(
                Request::create('/'),
                $authorization,
                $pending,
                $user,
                AuthorizationApproval::Prompt,
                fn () => $this->app->make(ServerFactory::class)->authorizationServer()
                    ->completeAuthorizationRequest($authorization, new Response),
            );
        } finally {
            $this->assertSame(0, AuthCode::count());
            $this->assertSame($consent === null ? 0 : 1, Consent::count());
            $this->assertSame($original, $consent?->fresh()->getRawOriginal());
        }
    }

    public function testRejectedListenerRollsBackConsentAndItsOwnWrites(): void
    {
        [$authorization, $pending, $user] = $this->authorization();
        $failure = new RuntimeException('Approval rejected.');
        Event::listen(AuthorizationApproved::class, static function () use ($failure): never {
            Consent::sole()->update(['scopes' => ['other']]);
            throw $failure;
        });

        try {
            $this->app->make(AuthorizationFinalizer::class)->approve(
                Request::create('/'),
                $authorization,
                $pending,
                $user,
                AuthorizationApproval::Prompt,
                fn () => $this->fail('A rejected approval must not complete.'),
            );
            $this->fail('Approval must propagate the listener failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, Consent::count());
        $this->assertSame(0, AuthCode::count());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testRememberedReuseRechecksConsentWithoutExtendingItsLifetime(bool $expired): void
    {
        [$authorization, $pending, $user] = $this->authorization();
        $consent = Consent::factory()->create([
            'client_id' => $authorization->getClient()->getIdentifier(), 'user_id' => $user->id,
            'scopes' => ['read', 'write'], 'remembered_until' => Date::now()->addMinutes($expired ? -1 : 10),
        ]);
        $expiry = $consent->getRawOriginal('remembered_until');
        $result = $this->app->make(AuthorizationFinalizer::class)->approve(
            Request::create('/'),
            $authorization,
            $pending,
            $user,
            AuthorizationApproval::Remembered,
            static fn (): string => 'approved',
        );

        $this->assertSame($expired ? null : 'approved', $result);
        $this->assertSame($expiry, $consent->fresh()->getRawOriginal('remembered_until'));

        $consent->delete();
        $this->assertNull($this->app->make(AuthorizationFinalizer::class)->approve(
            Request::create('/'),
            $authorization,
            $pending,
            $user,
            AuthorizationApproval::Remembered,
            fn () => $this->fail('Deleted remembered consent must not complete.'),
        ));
        $this->assertSame(0, Consent::count());
    }

    public function testPolicyApprovalDoesNotRememberUserChoices(): void
    {
        [$authorization, $pending, $user] = $this->authorization();
        $finalizer = $this->app->make(AuthorizationFinalizer::class);
        $finalizer->approve(Request::create('/'), $authorization, $pending, $user, AuthorizationApproval::Policy, static fn (): bool => true);
        $consent = Consent::sole();
        $this->assertFalse($consent->isRemembered());
        $this->assertNull($consent->scopes);

        $consent->update(['remembered_until' => null, 'scopes' => ['read']]);
        $finalizer->approve(Request::create('/'), $authorization, $pending, $user, AuthorizationApproval::Policy, static fn (): bool => true);
        $this->assertTrue($consent->fresh()->isRemembered());
        $this->assertSame(['read'], $consent->fresh()->scopes);
    }

    /**
     * Prepare an authenticated authorization and its trusted presentation.
     */
    protected function authorization(): array
    {
        $client = ClientFactory::new()->create(['redirect_uris' => ['https://client.example/callback']]);
        $user = new User;
        $user->id = 42;
        $authorization = new AuthorizationRequest;
        $authorization->setGrantTypeId('authorization_code');
        $authorization->setClient(new ClientEntity($client->id, $client->name, $client->redirect_uris, true));
        $authorization->setUser(new UserEntity((string) $user->id));
        $authorization->setScopes([new Scope('read'), new Scope('write')]);
        $authorization->setAuthorizationApproved(true);

        return [$authorization, [
            'parameters' => [], 'scopes' => ['read', 'write'], 'permissions' => [], 'attributes' => [],
            'provider' => 'users', 'guard' => 'web', 'grant_type' => 'authorization_code', 'remember_consent_for' => null,
        ], $user];
    }
}
