<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\NewAccessToken;
use Hypervel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class NewAccessTokenTest extends TestCase
{
    /**
     * Test to array method
     */
    public function testToArrayMethod(): void
    {
        $accessToken = new PersonalAccessToken([
            'name' => 'Test Token',
            'token' => 'test-hash',
            'abilities' => ['*']
        ]);
        
        $newToken = new NewAccessToken($accessToken, 'test-plain-text-token');
        
        $array = $newToken->toArray();
        
        $this->assertArrayHasKey('accessToken', $array);
        $this->assertArrayHasKey('plainTextToken', $array);
        $this->assertSame($accessToken, $array['accessToken']);
        $this->assertSame('test-plain-text-token', $array['plainTextToken']);
    }

    /**
     * Test to json method
     */
    public function testToJsonMethod(): void
    {
        $accessToken = new PersonalAccessToken([
            'name' => 'Test Token',
            'token' => 'test-hash',
            'abilities' => ['*']
        ]);
        
        $newToken = new NewAccessToken($accessToken, 'test-plain-text-token');
        
        $json = $newToken->toJson();
        
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('accessToken', $decoded);
        $this->assertArrayHasKey('plainTextToken', $decoded);
        $this->assertSame('test-plain-text-token', $decoded['plainTextToken']);
    }

    /**
     * Test toString method
     */
    public function testToStringMethod(): void
    {
        $accessToken = new PersonalAccessToken([
            'name' => 'Test Token',
            'token' => 'test-hash',
            'abilities' => ['*']
        ]);
        
        $newToken = new NewAccessToken($accessToken, 'test-plain-text-token');
        
        $string = (string) $newToken;
        
        $this->assertJson($string);
        $this->assertSame($newToken->toJson(), $string);
    }
}