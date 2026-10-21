<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Passport\Scope;
use Hypervel\Tests\TestCase;

class ScopeTest extends TestCase
{
    public function testScopeCanBeConvertedToArray(): void
    {
        $scope = new Scope('user', 'get user information');
        $this->assertEquals([
            'id' => 'user',
            'description' => 'get user information',
        ], $scope->toArray());
    }

    public function testScopeCanBeConvertedToJson(): void
    {
        $scope = new Scope('user', 'get user information');
        $this->assertEquals(json_encode([
            'id' => 'user',
            'description' => 'get user information',
        ]), $scope->toJson());
    }
}
