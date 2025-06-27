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
    public function it_returns_context_class_when_no_arguments_provided(): void
    {
        $result = context();
        
        $this->assertEquals(Context::class, $result);
    }

    /** @test */
    public function it_gets_single_context_value_with_default(): void
    {
        context(['test_key' => 'test_value']);
        
        $result = context('test_key');
        $this->assertEquals('test_value', $result);
        
        $result = context('non_existent', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    /** @test */
    public function it_sets_multiple_context_values_when_array_provided(): void
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
    public function it_returns_null_for_non_existent_key_without_default(): void
    {
        $result = context('non_existent_key');
        
        $this->assertNull($result);
    }
}
