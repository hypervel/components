<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AstVisitorRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        AstVisitorRegistry::clear();

        parent::tearDown();
    }

    public function testInsertAndExists()
    {
        $this->assertFalse(AstVisitorRegistry::exists('FooVisitor'));

        AstVisitorRegistry::insert('FooVisitor');

        $this->assertTrue(AstVisitorRegistry::exists('FooVisitor'));
    }

    public function testInsertWithPriority()
    {
        AstVisitorRegistry::insert('LowPriority', 0);
        AstVisitorRegistry::insert('HighPriority', 100);

        $queue = clone AstVisitorRegistry::getQueue();
        $items = [];
        foreach ($queue as $item) {
            $items[] = $item;
        }

        $this->assertSame('HighPriority', $items[0]);
        $this->assertSame('LowPriority', $items[1]);
    }

    public function testClearResetsAllState()
    {
        AstVisitorRegistry::insert('FooVisitor');
        $this->assertTrue(AstVisitorRegistry::exists('FooVisitor'));

        AstVisitorRegistry::clear();

        $this->assertFalse(AstVisitorRegistry::exists('FooVisitor'));
        $this->assertTrue(AstVisitorRegistry::getQueue()->isEmpty());
    }

    public function testExistsUsesStrictComparison()
    {
        AstVisitorRegistry::insert('FooVisitor');

        $this->assertTrue(AstVisitorRegistry::exists('FooVisitor'));
        $this->assertFalse(AstVisitorRegistry::exists('BarVisitor'));
    }
}
