<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\Aspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\RewriteCollection;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AspectTest extends TestCase
{
    public function testParseMoreThanOneMethods(): void
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo::test1',
            'Demo::test2',
        ]);

        $res = Aspect::parse('Demo');

        $this->assertEquals(['test1', 'test2'], $res->getMethods());
    }

    public function testParseOneMethod(): void
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo::test1',
        ]);

        $res = Aspect::parse('Demo');

        $this->assertEquals(['test1'], $res->getMethods());
        $this->assertTrue($res->shouldRewrite('test1'));
    }

    public function testParseClass(): void
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

    public function testMatchClassPattern(): void
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

    #[DataProvider('constructorAndClassRules')]
    public function testClassRulesPreserveExplicitConstructorInterception(array $rules): void
    {
        foreach ($rules as $index => $rule) {
            AspectCollector::setAround('Aspect' . $index, [$rule]);
        }

        $collection = Aspect::parse('Demo');

        $this->assertTrue($collection->shouldRewrite('__construct'));
        $this->assertTrue($collection->shouldRewrite('test'));
    }

    /**
     * Provide constructor and class rules in either registration order.
     */
    public static function constructorAndClassRules(): array
    {
        return [
            [['Demo', 'Demo::__construct']],
            [['Demo::__construct', 'Demo']],
            [['Demo*', 'Demo::__construct']],
            [['Demo::__construct', 'Demo*']],
        ];
    }

    public function testMatchMethodPattern(): void
    {
        $aspect = 'App\Aspect\DebugAspect';

        AspectCollector::setAround($aspect, [
            'Demo::test*',
        ]);

        $res = Aspect::parse('Demo');
        $this->assertTrue($res->shouldRewrite('test1'));
        $this->assertFalse($res->shouldRewrite('no'));
    }

    public function testIsMatchClassRule(): void
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

    public function testIsMatch(): void
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

    #[DataProvider('constructorMatchingRules')]
    public function testConstructorMatchingRequiresAMethodRule(string $rule, bool $matches): void
    {
        $this->assertSame($matches, Aspect::isMatch('Demo', '__construct', $rule));
    }

    /**
     * Provide class-only and explicit method rules for constructors.
     */
    public static function constructorMatchingRules(): array
    {
        return [
            ['Demo', false],
            ['Demo*', false],
            ['Demo::__construct', true],
            ['Demo*::__construct', true],
            ['Demo::*', true],
            ['Demo*::*', true],
        ];
    }
}
