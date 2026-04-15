<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\Aspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\RewriteCollection;
use Hypervel\Tests\TestCase;

class AspectTest extends TestCase
{
    public function testParseMoreThanOneMethods()
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo::test1',
            'Demo::test2',
        ]);

        $res = Aspect::parse('Demo');

        $this->assertEquals(['test1', 'test2'], $res->getMethods());
    }

    public function testParseOneMethod()
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo::test1',
        ]);

        $res = Aspect::parse('Demo');

        $this->assertEquals(['test1'], $res->getMethods());
        $this->assertTrue($res->shouldRewrite('test1'));
    }

    public function testParseClass()
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo',
        ]);

        $res = Aspect::parse('Demo');
        $this->assertSame(RewriteCollection::CLASS_LEVEL, $res->getLevel());
        $this->assertFalse($res->shouldRewrite('__construct'));
        $this->assertTrue($res->shouldRewrite('test'));
    }

    public function testMatchClassPattern()
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo*',
        ]);

        $res = Aspect::parse('Demo');
        $this->assertTrue($res->shouldRewrite('test1'));

        $res = Aspect::parse('DemoUser');
        $this->assertTrue($res->shouldRewrite('test1'));
    }

    public function testMatchMethodPattern()
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo::test*',
        ]);

        $res = Aspect::parse('Demo');
        $this->assertTrue($res->shouldRewrite('test1'));
        $this->assertFalse($res->shouldRewrite('no'));
    }

    public function testIsMatchClassRule()
    {
        $rule = 'Foo/Bar';
        $this->assertSame([true, null], Aspect::isMatchClassRule('Foo/Bar', $rule));
        $this->assertSame([true, 'method'], Aspect::isMatchClassRule('Foo/Bar::method', $rule));
        $this->assertSame([false, null], Aspect::isMatchClassRule('Foo/Bar/Baz', $rule));

        $rule = 'Foo/B*';
        $this->assertSame([true, null], Aspect::isMatchClassRule('Foo/Bar', $rule));
        $this->assertSame([true, null], Aspect::isMatchClassRule('Foo/Bar/Baz', $rule));

        $rule = 'F*/Bar';
        $this->assertSame([true, null], Aspect::isMatchClassRule('Foo/Bar', $rule));
        $this->assertSame([false, null], Aspect::isMatchClassRule('Foo/Bar/Baz', $rule));

        $rule = 'F*/Ba*';
        $this->assertSame([true, null], Aspect::isMatchClassRule('Foo/Bar', $rule));
        $this->assertSame([true, 'method'], Aspect::isMatchClassRule('Foo/Bar::method', $rule));
        $this->assertSame([true, null], Aspect::isMatchClassRule('Foo/Bar/Baz', $rule));

        $rule = 'Foo/Bar::method';
        $this->assertSame([true, 'method'], Aspect::isMatchClassRule('Foo/Bar', $rule));
        $this->assertSame([true, 'method'], Aspect::isMatchClassRule('Foo/Bar::method', $rule));
        $this->assertSame([false, null], Aspect::isMatchClassRule('Foo/Bar/Baz::method', $rule));

        $rule = 'Foo/Bar::metho*';
        $this->assertSame([true, 'metho*'], Aspect::isMatchClassRule('Foo/Bar', $rule));
        $this->assertSame([true, 'method'], Aspect::isMatchClassRule('Foo/Bar::method', $rule));
        $this->assertSame([false, null], Aspect::isMatchClassRule('Foo/Bar/Baz::method', $rule));
    }

    public function testIsMatch()
    {
        $rule = 'Foo/Bar';
        $this->assertTrue(Aspect::isMatch('Foo/Bar', 'test', $rule));
        $this->assertFalse(Aspect::isMatch('Foo/Bar/Baz', 'test', $rule));

        $rule = 'Foo/B*';
        $this->assertTrue(Aspect::isMatch('Foo/Bar', 'test', $rule));
        $this->assertTrue(Aspect::isMatch('Foo/Bar/Baz', '*', $rule));

        $rule = 'F*/Bar';
        $this->assertTrue(Aspect::isMatch('Foo/Bar', '*', $rule));
        $this->assertFalse(Aspect::isMatch('Foo/Bar/Baz', '*', $rule));

        $rule = 'F*/Ba*';
        $this->assertTrue(Aspect::isMatch('Foo/Bar', '*', $rule));
        $this->assertTrue(Aspect::isMatch('Foo/Bar/Baz', '*', $rule));

        $rule = 'Foo/Bar::method';
        $this->assertTrue(Aspect::isMatch('Foo/Bar', 'method', $rule));
        $this->assertFalse(Aspect::isMatch('Foo/Bar', 'test', $rule));
        $this->assertFalse(Aspect::isMatch('Foo/Bar/Baz', 'method', $rule));

        $rule = 'Foo/Bar::metho*';
        $this->assertTrue(Aspect::isMatch('Foo/Bar', 'method', $rule));
        $this->assertTrue(Aspect::isMatch('Foo/Bar', 'method2', $rule));
        $this->assertFalse(Aspect::isMatch('Foo/Bar/Baz', 'method', $rule));
        $this->assertFalse(Aspect::isMatch('Foo/Bar', 'test', $rule));
    }
}
