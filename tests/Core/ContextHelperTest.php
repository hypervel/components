<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core;

use ArrayObject;
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
    public function itReturnsContextInstanceObjectWhenNoArgumentsProvided(): void
    {
        $result = context();
        
        // is an object that has all methods of Context class
        $this->isInstanceOf(ArrayObject::class, $result);
        $result->set('test_key', 'test_value');
        $this->assertEquals('test_value', $result->get('test_key'));
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

        $this->isInstanceOf(ArrayObject::class, $result);
        $this->assertEquals(123, context('user_id'));
        $this->assertEquals('abc123', context('session_id'));

        // Check if the context is set correctly
        foreach ($contextData as $key => $value) {
            $this->assertEquals($value, context($key));
        }
    }

    /** @test */
    public function itReturnsNullForNonExistentKeyWithoutDefault(): void
    {
        $result = context('non_existent_key');
        
        $this->assertNull($result);
    }
}
