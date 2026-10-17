<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Consent;
use Hypervel\Passport\ConsentRepository;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\Passport;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\RefreshTokenFamily;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\Date;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class ConsentTest extends PassportTestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testRememberingChoicesPreservesOnlyUnexpiredUnrelatedApproval(bool $expired): void
    {
        $consent = Consent::factory()->create([
            'scopes' => ['read', 'write', 'other'],
            'permissions' => ['profile.claims' => ['name', 'email', 'address']],
            'remembered_until' => $expired ? Date::now()->subMinute() : null,
        ]);

        $this->app->make(ConsentRepository::class)->remember(
            $consent,
            ['scopes' => ['read', 'write'], 'permissions' => ['profile.claims' => ['name', 'email']]],
            ['scopes' => ['read'], 'permissions' => ['profile.claims' => ['name']]],
            new DateInterval('PT1H'),
        );

        $consent->refresh();
        $this->assertEqualsCanonicalizing($expired ? ['read'] : ['read', 'other'], $consent->scopes);
        $this->assertEqualsCanonicalizing($expired ? ['name'] : ['name', 'address'], $consent->permissions['profile.claims']);
        $this->assertTrue($consent->covers(['read'], ['profile.claims' => ['name']]));
        $this->assertFalse($consent->covers(['write']));
        $this->assertFalse($consent->covers(['read'], ['profile.claims' => ['email']]));

        $this->travel(1)->hour();
        $this->assertFalse($consent->covers(['read']));
    }

    public function testResourceApprovalDoesNotCombineScopesAcrossResources(): void
    {
        $consent = Consent::factory()->create();
        $repository = $this->app->make(ConsentRepository::class);

        foreach (['https://api.example.com/a' => ['read', 'write'], 'https://api.example.com/b' => ['read']] as $resource => $scopes) {
            $selection = ['scopes' => $scopes, 'permissions' => ['resources' => [$resource => $scopes]]];
            $repository->remember($consent, $selection, $selection, null);
        }

        $consent->refresh();
        $this->assertTrue($consent->covers(['write'], ['resources' => ['https://api.example.com/a' => ['write']]]));
        $this->assertTrue($consent->covers(['read'], ['resources' => ['https://api.example.com/b' => ['read']]]));
        $this->assertFalse($consent->covers(['write'], ['resources' => ['https://api.example.com/b' => ['write']]]));
        $this->assertFalse($consent->covers(['write']));
        $this->assertFalse($consent->covers([], ['resources' => ['https://api.example.com/c' => []]]));
    }

    public function testForgettingApprovalDoesNotRevokeCredentials(): void
    {
        $consent = Consent::factory()->create();
        $token = Token::create(['id' => 'access', 'client_id' => $consent->client_id, 'consent_id' => $consent->id, 'revoked' => false]);

        $this->assertTrue($consent->isRemembered());
        $this->assertTrue($consent->forget());
        $this->assertFalse($consent->fresh()->isRemembered());
        $this->assertFalse($token->fresh()->revoked);
    }

    #[TestWith(['delete'])]
    #[TestWith(['revoke'])]
    #[TestWith(['revoke', false])]
    public function testRevokingConsentRevokesOnlyItsCredentialsAndRetainsReplayEvidence(string $method, bool $deviceEnabled = true): void
    {
        Passport::$deviceCodeGrantEnabled = $deviceEnabled;
        $consent = Consent::factory()->create();
        $other = Consent::factory()->create();

        foreach ([$consent, $other] as $authorization) {
            $attributes = ['consent_id' => $authorization->id, 'revoked' => false];
            $family = RefreshTokenFamily::create(['consent_id' => $authorization->id]);
            Token::create([...$attributes, 'id' => $authorization->id, 'client_id' => $authorization->client_id]);
            RefreshToken::create([...$attributes, 'id' => $authorization->id, 'access_token_id' => $authorization->id, 'family_id' => $family->id]);
            AuthCode::create([...$attributes, 'id' => $authorization->id, 'client_id' => $authorization->client_id, 'user_id' => 1]);
            DeviceCode::create([
                ...$attributes, 'id' => $authorization->id, 'client_id' => $authorization->client_id,
                'user_code' => $authorization === $consent ? '11111111' : '22222222', 'scopes' => [],
            ]);
        }

        $this->assertTrue($consent->{$method}());
        $this->assertNull($consent->fresh());
        $this->assertNotNull($other->fresh());

        foreach ([AuthCode::class, DeviceCode::class, RefreshToken::class, Token::class] as $model) {
            $this->assertTrue($model::findOrFail($consent->id)->revoked);
            $this->assertFalse($model::findOrFail($other->id)->revoked);
        }

        $this->assertNotNull(RefreshTokenFamily::where('consent_id', $consent->id)->firstOrFail()->revoked_at);
        $this->assertNull(RefreshTokenFamily::where('consent_id', $other->id)->firstOrFail()->revoked_at);
    }

    public function testRejectedDeletionDoesNotRevokeCredentials(): void
    {
        $consent = Consent::factory()->create();
        $token = Token::create(['id' => 'access', 'client_id' => $consent->client_id, 'consent_id' => $consent->id, 'revoked' => false]);
        Consent::deleting(static fn (): bool => false);

        $this->assertFalse($consent->delete());
        $this->assertNotNull($consent->fresh());
        $this->assertFalse($token->fresh()->revoked);
    }

    public function testDeletionFailureRollsBackRevocation(): void
    {
        $consent = Consent::factory()->create();
        $token = Token::create(['id' => 'access', 'client_id' => $consent->client_id, 'consent_id' => $consent->id, 'revoked' => false]);
        $failure = new RuntimeException('Deletion failed.');
        Consent::deleted(static function () use ($failure): never {
            throw $failure;
        });

        try {
            $consent->delete();
            $this->fail('Deletion must propagate the failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue(Consent::whereKey($consent->id)->exists());
        $this->assertFalse($token->fresh()->revoked);
    }

    public function testProviderlessIdentityAndRememberedExpiry(): void
    {
        $consent = Consent::factory()->create(['provider' => '', 'remembered_until' => Date::now()->addMinute()]);
        $repository = $this->app->make(ConsentRepository::class);

        $this->assertTrue($consent->isRemembered());
        $this->assertTrue($repository->findForUser($consent->client_id, null, $consent->user_id)->is($consent));
        $this->assertNull($repository->findForUser($consent->client_id, 'users', $consent->user_id));
        $this->assertTrue($consent->client->consents()->first()->is($consent));

        $this->travel(1)->minute();
        $this->assertFalse($consent->isRemembered());
    }
}
