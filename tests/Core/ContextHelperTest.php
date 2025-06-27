<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core;

use PHPUnit\Framework\TestCase;
use Hypervel\Context\Context;

class ContextHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear any existing context
        if (method_exists(Context::class, 'destroyAll')) {
            Context::destroyAll();
        }
    }

    protected function tearDown(): void
    {
        // Clean up context after each test
        if (method_exists(Context::class, 'destroyAll')) {
            Context::destroyAll();
        }
        
        parent::tearDown();
    }

    /** @test */
    public function itReturnsContextClassWhenNoArgumentsProvided(): void
    {
        $result = context();
        
        $this->assertEquals(Context::class, $result);
    }

    /** @test */
    public function itGetsSingleContextValueWithDefault(): void
    {
        context(['test_key' => 'test_value']);
        
        $result = context('test_key');
        $this->assertEquals('test_value', $result);
        
        $result = context('non_existent', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    /** @test */
    public function itSetsMultipleContextValuesWhenArrayProvided(): void
    {
        $contextData = [
            'user_id' => 123,
            'session_id' => 'abc123'
        ];
        
        $result = context($contextData);
        
        $this->assertEquals(Context::class, $result);
        $this->assertEquals(123, context('user_id'));
        $this->assertEquals('abc123', context('session_id'));
    }

    /** @test */
    public function itReturnsNullForNonExistentKeyWithoutDefault(): void
    {
        $result = context('non_existent_key');
        
        $this->assertNull($result);
    }
}
