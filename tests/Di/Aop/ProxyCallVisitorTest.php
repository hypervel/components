<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\Ast;
use Hypervel\Di\Aop\ProxyCallVisitor;
use Hypervel\Di\Aop\VisitorMetadata;
use Hypervel\Tests\TestCase;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class ProxyCallVisitorTest extends TestCase
{
    protected function tearDown(): void
    {
        AspectCollector::flushState();

        parent::tearDown();
    }

    public function testShouldRewrite()
    {
        $code = <<<'CODETEMPLATE'
<?php
abstract class SomeClass
{
    abstract protected function foo();

    protected function bar()
    {
    }
}
CODETEMPLATE;

        $ast = new Ast();
        $stmts = $ast->parse($code)[0];

        $aspect = 'App\Aspect\DebugAspect';
        AspectCollector::setAround($aspect, [
            'SomeClass',
        ]);

        $proxyCallVisitor = new ProxyCallVisitor(new VisitorMetadata('SomeClass'));

        $reflectionMethod = new ReflectionMethod($proxyCallVisitor, 'shouldRewrite');
        $this->assertFalse($reflectionMethod->invoke($proxyCallVisitor, $stmts->stmts[0]));
        $this->assertTrue($reflectionMethod->invoke($proxyCallVisitor, $stmts->stmts[1]));
    }

    public function testInterfaceShouldNotRewrite()
    {
        $aspect = 'App\Aspect\DebugAspect';
        AspectCollector::setAround($aspect, [
            'SomeClass',
        ]);

        $visitorMetadata = new VisitorMetadata('SomeClass');
        $proxyCallVisitor = new ProxyCallVisitor($visitorMetadata);

        $reflectionMethod = new ReflectionMethod($proxyCallVisitor, 'shouldRewrite');
        $this->assertTrue($reflectionMethod->invoke($proxyCallVisitor, new ClassMethod('foo')));

        $visitorMetadata->classLike = Node\Stmt\Interface_::class;
        $this->assertFalse($reflectionMethod->invoke($proxyCallVisitor, new ClassMethod('foo')));
    }
}
