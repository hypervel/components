<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Passport\Passport;
use Hypervel\Passport\Token;
use Hypervel\Tests\TestCase;

class TokenTest extends TestCase
{
    public function testTokenCanDetermineIfItHasScopes(): void
    {
        $token = new Token(['scopes' => ['user']]);

        $this->assertTrue($token->can('user'));
        $this->assertFalse($token->can('something'));
        $this->assertTrue($token->cant('something'));
        $this->assertFalse($token->cant('user'));
        $this->assertTrue($token->cant('user:read'));

        $token = new Token(['scopes' => ['*']]);

        $this->assertTrue($token->can('user'));
        $this->assertTrue($token->can('something'));
    }

    public function testTokenCanDetermineIfItHasInheritedScopes(): void
    {
        Passport::$withInheritedScopes = true;

        $token = new Token([
            'scopes' => [
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

        $token = new Token(['scopes' => ['*']]);

        $this->assertTrue($token->can('user'));
        $this->assertTrue($token->can('something'));
        $this->assertTrue($token->can('admin:webhooks:write'));
    }

    public function testTokenResolvesInheritedScopes(): void
    {
        $token = new Token;

        $inheritedScopes = (fn () => $this->resolveInheritedScopes('admin:webhooks:read'))->call($token);

        $this->assertSame([
            'admin',
            'admin:webhooks',
            'admin:webhooks:read',
        ], $inheritedScopes);
    }
}
