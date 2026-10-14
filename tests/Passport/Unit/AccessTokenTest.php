<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Passport;
use Hypervel\Tests\TestCase;

class AccessTokenTest extends TestCase
{
    public function testTokenAttributesAreAccessible(): void
    {
        $token = new AccessToken([
            'oauth_user_id' => 1,
            'oauth_client_id' => 2,
            'oauth_access_token_id' => 'token',
            'oauth_scopes' => ['*'],
            'foo' => 'bar',
        ]);

        $this->assertFalse($token->transient());

        $this->assertSame(1, $token->oauth_user_id);
        $this->assertSame(2, $token->oauth_client_id);
        $this->assertSame('token', $token->oauth_access_token_id);
        $this->assertSame(['*'], $token->oauth_scopes);
        $this->assertSame('bar', $token->foo);

        $this->assertTrue(isset($token->oauth_user_id));
        $this->assertTrue(isset($token->oauth_client_id));
        $this->assertTrue(isset($token->oauth_access_token_id));
        $this->assertTrue(isset($token->oauth_scopes));
        $this->assertTrue(isset($token->foo));
    }

    public function testTokenCanDetermineIfItHasScopes(): void
    {
        $token = new AccessToken(['oauth_scopes' => ['user']]);

        $this->assertTrue($token->can('user'));
        $this->assertFalse($token->can('something'));
        $this->assertTrue($token->cant('something'));
        $this->assertFalse($token->cant('user'));

        $this->assertTrue($token->cant('user:read'));

        $token = new AccessToken(['oauth_scopes' => ['*']]);
        $this->assertTrue($token->can('user'));
        $this->assertTrue($token->can('something'));
    }

    public function testTokenCanDetermineIfItHasInheritedScopes(): void
    {
        Passport::$withInheritedScopes = true;

        $token = new AccessToken([
            'oauth_scopes' => [
                'user',
                'group',
                'admin:webhooks:read',
            ],
        ]);

        $this->assertTrue($token->can('user'));
        $this->assertTrue($token->can('group'));
        $this->assertTrue($token->can('user:read'));
        $this->assertTrue($token->can('group:read'));
        $this->assertTrue($token->can('admin:webhooks:read'));

        $this->assertTrue($token->cant('admin:webhooks'));

        $this->assertFalse($token->can('something'));

        $token = new AccessToken(['oauth_scopes' => ['*']]);
        $this->assertTrue($token->can('user'));
        $this->assertTrue($token->can('something'));
        $this->assertTrue($token->can('admin:webhooks:write'));
    }

    public function testTokenResolvesInheritedScopes(): void
    {
        $token = new AccessToken;

        $inheritedScopes = (fn () => $this->resolveInheritedScopes('admin:webhooks:read'))->call($token);

        $this->assertSame([
            'admin',
            'admin:webhooks',
            'admin:webhooks:read',
        ], $inheritedScopes);
    }
}
