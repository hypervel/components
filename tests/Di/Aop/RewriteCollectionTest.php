<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\RewriteCollection;
use Hypervel\Tests\TestCase;

class RewriteCollectionTest extends TestCase
{
    public function testDefaultLevelIsMethodLevel()
    {
        $collection = new RewriteCollection('Foo');

        $this->assertSame(RewriteCollection::METHOD_LEVEL, $collection->getLevel());
    }

    public function testAddMethodAndShouldRewrite()
    {
        $collection = new RewriteCollection('Foo');
        $collection->add('bar');

        $this->assertTrue($collection->shouldRewrite('bar'));
        $this->assertFalse($collection->shouldRewrite('baz'));
    }

    public function testAddMultipleMethods()
    {
        $collection = new RewriteCollection('Foo');
        $collection->add(['bar', 'baz']);

        $this->assertTrue($collection->shouldRewrite('bar'));
        $this->assertTrue($collection->shouldRewrite('baz'));
        $this->assertFalse($collection->shouldRewrite('qux'));
    }

    public function testClassLevelRewritesAllMethodsExceptConstructor()
    {
        $collection = new RewriteCollection('Foo');
        $collection->setLevel(RewriteCollection::CLASS_LEVEL);

        $this->assertTrue($collection->shouldRewrite('bar'));
        $this->assertTrue($collection->shouldRewrite('baz'));
        $this->assertFalse($collection->shouldRewrite('__construct'));
    }

    public function testMethodPatternMatching()
    {
        $collection = new RewriteCollection('Foo');
        $collection->add('get*');

        $this->assertTrue($collection->shouldRewrite('getName'));
        $this->assertTrue($collection->shouldRewrite('getAge'));
        $this->assertFalse($collection->shouldRewrite('setName'));
    }

    public function testGetClass()
    {
        $collection = new RewriteCollection('App\Foo');

        $this->assertSame('App\Foo', $collection->getClass());
    }

    public function testGetMethods()
    {
        $collection = new RewriteCollection('Foo');
        $collection->add('bar');
        $collection->add('baz');

        $this->assertSame(['bar', 'baz'], $collection->getMethods());
    }

    public function testGetShouldNotRewriteMethods()
    {
        $collection = new RewriteCollection('Foo');

        $this->assertSame(['__construct'], $collection->getShouldNotRewriteMethods());
    }
}
