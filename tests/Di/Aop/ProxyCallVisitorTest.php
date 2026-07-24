<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Attribute;
use Closure;
use Error;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\Ast;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Di\Aop\ProxyCallVisitor;
use Hypervel\Di\Aop\ProxyMarker;
use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use ValueError;

class ProxyCallVisitorTest extends TestCase
{
    public function testPreservesArgumentIntrospectionAcrossCallShapes(): void
    {
        $method = <<<'PHP'
    public function inspect(string $first = 'first', string $second = 'second', string ...$rest): array
    {
        return [func_num_args(), func_get_args(), $first, $second, $rest];
    }
PHP;
        $nativeClass = $this->className('NativeArgumentShapes');
        $proxyClass = $this->className('ProxyArgumentShapes');

        $this->evaluate($this->classSource($nativeClass, $method));
        $this->evaluate($this->generate(
            $proxyClass,
            '/original/ProxyArgumentShapes.php',
            $this->classSource($proxyClass, $method)
        ));

        $native = new $nativeClass;
        $proxy = new $proxyClass;
        $calls = [
            static fn (object $target): array => $target->inspect(),
            static fn (object $target): array => $target->inspect('changed'),
            static fn (object $target): array => $target->inspect(second: 'changed'),
            static fn (object $target): array => $target->inspect('one', 'two', 'three', 'four'),
            static fn (object $target): array => $target->inspect(named: 'value'),
            static fn (object $target): array => $target->inspect('one', named: 'value'),
        ];

        foreach ($calls as $call) {
            $this->assertSame($call($native), $call($proxy));
        }
    }

    public function testPreservesMethodLocalStateDefaultsAndMagicConstants(): void
    {
        $className = $this->className();
        $sourcePath = '/original/aop/StatefulTarget.php';
        $source = $this->classSource($className, <<<'PHP'
    /** Original documentation. */
    #[\Hypervel\Tests\Di\Aop\ProxyMethodAttribute]
    public function inspect(object $value = new \stdClass): array
    {
        static $calls = 0;
        ++$calls;

        $nested = function (string $value): array {
            $inner = fn (): array => [__FUNCTION__, __METHOD__];

            return [
                __FUNCTION__,
                __METHOD__,
                __FILE__,
                __DIR__,
                __LINE__,
                func_num_args(),
                func_get_args(),
                $inner(),
            ];
        };

        return [
            $value,
            $calls,
            __FUNCTION__,
            __METHOD__,
            __FILE__,
            __DIR__,
            __LINE__,
            $nested('nested'),
        ];
    }
PHP);

        $outerLine = substr_count(substr($source, 0, strpos($source, '$nested = function')), "\n") + 1;
        $innerLine = substr_count(substr($source, 0, strpos($source, '$inner = fn')), "\n") + 1;
        $outerDescriptor = "{closure:{$className}::inspect():{$outerLine}}";
        $innerDescriptor = "{closure:{$outerDescriptor}:{$innerLine}}";

        $generated = $this->generate($className, $sourcePath, $source);
        $this->evaluate($generated);

        $first = (new $className)->inspect();
        $second = (new $className)->inspect();

        $this->assertNotSame($first[0], $second[0]);
        $this->assertSame([1, 2], [$first[1], $second[1]]);
        $this->assertSame('inspect', $first[2]);
        $this->assertSame($className . '::inspect', $first[3]);
        $this->assertSame($sourcePath, $first[4]);
        $this->assertSame(dirname($sourcePath), $first[5]);
        $this->assertIsInt($first[6]);
        $this->assertSame([$outerDescriptor, $outerDescriptor], [$first[7][0], $first[7][1]]);
        $this->assertSame([$sourcePath, dirname($sourcePath)], [$first[7][2], $first[7][3]]);
        $this->assertSame([1, ['nested']], [$first[7][5], $first[7][6]]);
        $this->assertSame([$innerDescriptor, $innerDescriptor], $first[7][7]);
        $this->assertCount(1, (new ReflectionMethod($className, 'inspect'))->getAttributes(ProxyMethodAttribute::class));
        $this->assertSame(1, substr_count($generated, '/** Original documentation. */'));
    }

    public function testPreservesReferenceMutationFromAspectsAndOriginalBodies(): void
    {
        $class = $this->proxyClass(
            <<<'PHP'
    public function mutate(int &$value, string &...$rest): void
    {
        ++$value;

        foreach ($rest as &$item) {
            $item .= '-body';
        }
    }

PHP,
            MutatingArgumentsAspect::class
        );

        $value = 1;
        $first = 'first';
        $second = 'second';

        (new $class)->mutate($value, $first, $second);

        $this->assertSame(12, $value);
        $this->assertSame('first-aspect-body', $first);
        $this->assertSame('second-body', $second);
    }

    public function testNativeClosureDescriptorFormatHasNotDrifted(): void
    {
        $fixture = new NativeClosureDescriptorFixture;

        $this->assertMatchesRegularExpression(
            '/^\{closure:' . preg_quote(NativeClosureDescriptorFixture::class, '/') . '::descriptor\(\):\d+\}$/D',
            $fixture->descriptor()
        );
        $this->assertMatchesRegularExpression(
            '/^\{closure:\{closure:' . preg_quote(NativeClosureDescriptorFixture::class, '/')
                . '::nestedDescriptor\(\):\d+\}:\d+\}$/D',
            $fixture->nestedDescriptor()
        );
    }

    public function testNormalizesLeadingBackslashesInMagicDescriptors(): void
    {
        $className = $this->className('LeadingSlash');
        $source = $this->classSource($className, <<<'PHP'
    public function descriptor(): array
    {
        return [__METHOD__, (fn (): string => __FUNCTION__)()];
    }
PHP);

        $this->evaluate($this->generate(
            '\\' . $className,
            '/original/LeadingSlash.php',
            $source
        ));

        [$method, $closure] = (new $className)->descriptor();

        $this->assertSame($className . '::descriptor', $method);
        $this->assertStringStartsWith('{closure:' . $className . '::descriptor():', $closure);
    }

    public function testPreservesClosuresDeclaredInsideNestedNamedFunctions(): void
    {
        $className = $this->className('NestedFunction');
        $namespace = substr($className, 0, strrpos($className, '\\'));
        $source = $this->classSource($className, <<<'PHP'
    public function inspect(): array
    {
        function localFunction(): array
        {
            $closure = fn (): array => [__FUNCTION__, __METHOD__];

            return [__FUNCTION__, __METHOD__, $closure()];
        }

        return localFunction();
    }
PHP);
        $closureLine = substr_count(substr($source, 0, strpos($source, '$closure = fn')), "\n") + 1;
        $function = $namespace . '\localFunction';

        $this->evaluate($this->generate($className, '/original/NestedFunction.php', $source));

        $this->assertSame(
            [$function, $function, ["{closure:{$function}():{$closureLine}}", "{closure:{$function}():{$closureLine}}"]],
            (new $className)->inspect()
        );
    }

    public function testPreservesPrivateStaticVoidAndNeverMethods(): void
    {
        $class = $this->proxyClass(
            <<<'PHP'
    public function callPrivate(int $value): int
    {
        return $this->privateValue($value);
    }

    private function privateValue(int $value): int
    {
        return $value * 2;
    }

    public static function staticValue(int $value): int
    {
        return $value + 1;
    }

    public function touch(int &$value): void
    {
        ++$value;
    }

    public function stop(): never
    {
        throw new \RuntimeException('stopped');
    }
PHP,
            rule: '*'
        );

        $instance = new $class;
        $value = 1;

        $this->assertSame(6, $instance->callPrivate(3));
        $this->assertSame(4, $class::staticValue(3));
        $this->assertNull($instance->touch($value));
        $this->assertSame(2, $value);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stopped');
        $instance->stop();
    }

    public function testUsesCollisionFreeGeneratedNames(): void
    {
        $className = $this->className();
        $hash = substr(hash('sha256', $className . '::target'), 0, 12);
        $source = $this->classSource($className, <<<PHP
    public function target(int \$value): int
    {
        \$__hypervelAopCount_{$hash} = 10;

        return \$value + \$__hypervelAopCount_{$hash} + func_num_args();
    }

    private function __hypervelAopOriginal_{$hash}(): int
    {
        return -1;
    }
PHP);

        $generated = $this->generate($className, '/original/Collision.php', $source, rule: 'target');
        $this->evaluate($generated);

        $this->assertSame(14, (new $className)->target(3));
        $this->assertStringContainsString("__hypervelAopOriginal_{$hash}_1", $generated);
        $this->assertStringContainsString("__hypervelAopCount_{$hash}_1", $generated);
    }

    public function testMarksProxiedTraitsWithoutInjectingMethods(): void
    {
        $traitName = $this->className('GeneratedTrait');
        $traitSource = $this->traitSource($traitName, <<<'PHP'
    public function value(): string
    {
        return 'proxied-trait';
    }

    public function descriptor(): string
    {
        return (fn (): string => __FUNCTION__)();
    }
PHP);

        $closureLine = substr_count(substr($traitSource, 0, strpos($traitSource, 'fn (): string')), "\n") + 1;

        $this->evaluate($this->generate($traitName, '/original/GeneratedTrait.php', $traitSource));

        $className = $this->className('TraitConsumer');
        $separator = strrpos($className, '\\');
        $namespace = substr($className, 0, $separator);
        $shortName = substr($className, $separator + 1);

        eval("namespace {$namespace}; class {$shortName} { use \\{$traitName} { value as aliasedValue; } }");

        $instance = new $className;

        $this->assertSame('proxied-trait', $instance->value());
        $this->assertSame('proxied-trait', $instance->aliasedValue());
        $this->assertSame("{closure:{$traitName}::descriptor():{$closureLine}}", $instance->descriptor());
        $this->assertContains(ProxyMarker::class, class_uses_recursive($instance));
        $this->assertFalse(method_exists($instance, '__proxyCall'));
    }

    public function testProxiesEnumMethodsAndMarksTheGeneratedEnum(): void
    {
        $enumName = $this->className('GeneratedEnum');
        $separator = strrpos($enumName, '\\');
        $namespace = substr($enumName, 0, $separator);
        $shortName = substr($enumName, $separator + 1);
        $source = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

enum {$shortName}
{
    case Value;

    public function label(): string
    {
        return 'proxied-enum';
    }
}
PHP;

        $this->evaluate($this->generate($enumName, '/original/GeneratedEnum.php', $source));

        $this->assertSame('proxied-enum', $enumName::Value->label());
        $this->assertContains(ProxyMarker::class, class_uses_recursive($enumName));
    }

    public function testComposesMultipleProxiedTraitsWithoutMarkerCollisions(): void
    {
        $firstTrait = $this->className('FirstGeneratedTrait');
        $secondTrait = $this->className('SecondGeneratedTrait');

        $this->evaluate($this->generate(
            $firstTrait,
            '/original/FirstGeneratedTrait.php',
            $this->traitSource($firstTrait, <<<'PHP'
    public function first(): string
    {
        return 'first';
    }
PHP)
        ));
        $this->evaluate($this->generate(
            $secondTrait,
            '/original/SecondGeneratedTrait.php',
            $this->traitSource($secondTrait, <<<'PHP'
    public function second(): string
    {
        return 'second';
    }
PHP)
        ));

        $className = $this->className('MultipleTraitConsumer');
        $separator = strrpos($className, '\\');
        $namespace = substr($className, 0, $separator);
        $shortName = substr($className, $separator + 1);

        eval("namespace {$namespace}; class {$shortName} { use \\{$firstTrait}, \\{$secondTrait}; }");

        $instance = new $className;

        $this->assertSame('first', $instance->first());
        $this->assertSame('second', $instance->second());
        $this->assertContains(ProxyMarker::class, class_uses_recursive($instance));
    }

    public function testPreservesNamespaceFunctionResolution(): void
    {
        $className = $this->className('NamespaceFunctions');
        $separator = strrpos($className, '\\');
        $namespace = substr($className, 0, $separator);
        $shortName = substr($className, $separator + 1);
        $source = <<<PHP
<?php

namespace {$namespace};

function func_num_args(): int
{
    return 99;
}

function func_get_args(): array
{
    return ['shadow'];
}

function func_get_arg(int \$position): string
{
    return "shadow-{\$position}";
}

class {$shortName}
{
    public function inspect(string \$value): array
    {
        return [func_num_args(), func_get_args(), func_get_arg(0)];
    }
}
PHP;

        $this->evaluate($this->generate($className, '/original/NamespaceFunctions.php', $source));

        $this->assertSame([99, ['shadow'], 'shadow-0'], (new $className)->inspect('value'));
    }

    public function testRewritesImportedAndNamedArgumentBuiltins(): void
    {
        $className = $this->className('ImportedFunctions');
        $separator = strrpos($className, '\\');
        $namespace = substr($className, 0, $separator);
        $shortName = substr($className, $separator + 1);
        $source = <<<PHP
<?php

namespace {$namespace};

use function func_get_arg;
use function func_num_args as argument_count;

class {$shortName}
{
    public function inspect(string \$value): array
    {
        return [argument_count(), func_num_args(), func_get_arg(position: 0)];
    }
}
PHP;

        $this->evaluate($this->generate($className, '/original/ImportedFunctions.php', $source));

        $this->assertSame([1, 1, 'value'], (new $className)->inspect('value'));
    }

    public function testLeavesDefinitelyCustomUnpackedFunctionsUntouched(): void
    {
        $className = $this->className('CustomUnpackedFunction');
        $separator = strrpos($className, '\\');
        $namespace = substr($className, 0, $separator);
        $shortName = substr($className, $separator + 1);
        $source = <<<PHP
<?php

namespace {$namespace};

function func_num_args(mixed ...\$arguments): int
{
    return count(\$arguments);
}

class {$shortName}
{
    public function inspect(): int
    {
        return namespace\\func_num_args(...[1, 2]);
    }
}
PHP;

        $this->evaluate($this->generate($className, '/original/CustomUnpackedFunction.php', $source));

        $this->assertSame(2, (new $className)->inspect());
    }

    public function testLeavesFirstClassArgumentCallableBehaviorUntouched(): void
    {
        $class = $this->proxyClass(<<<'PHP'
    public function callback(): \Closure
    {
        return func_num_args(...);
    }
PHP);

        $callback = (new $class)->callback();

        $this->assertInstanceOf(Closure::class, $callback);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot call func_num_args() dynamically');
        $callback();
    }

    public function testLeavesAlreadyInvalidFullyQualifiedDynamicArgumentCallsNative(): void
    {
        $method = <<<'PHP'
    public function numArgs(): mixed
    {
        return call_user_func('\func_num_args');
    }

    public function getArgs(): mixed
    {
        return call_user_func_array('\func_get_args', []);
    }
PHP;
        $nativeClass = $this->className('NativeDynamicArguments');
        $proxyClass = $this->className('ProxyDynamicArguments');

        $this->evaluate($this->classSource($nativeClass, $method));
        $this->evaluate($this->generate(
            $proxyClass,
            '/original/ProxyDynamicArguments.php',
            $this->classSource($proxyClass, $method)
        ));

        foreach (['numArgs', 'getArgs'] as $methodName) {
            $nativeError = null;
            $proxyError = null;

            try {
                (new $nativeClass)->{$methodName}();
            } catch (Error $error) {
                $nativeError = $error;
            }

            try {
                (new $proxyClass)->{$methodName}();
            } catch (Error $error) {
                $proxyError = $error;
            }

            $this->assertInstanceOf(Error::class, $nativeError);
            $this->assertInstanceOf(Error::class, $proxyError);
            $this->assertSame($nativeError->getMessage(), $proxyError->getMessage());
        }
    }

    public function testPreservesNativeFuncGetArgErrors(): void
    {
        $method = <<<'PHP'
    public function argument(int $position): mixed
    {
        return func_get_arg($position);
    }
PHP;
        $nativeClass = $this->className('NativeArgumentErrors');
        $proxyClass = $this->className('ProxyArgumentErrors');

        $this->evaluate($this->classSource($nativeClass, $method));
        $this->evaluate($this->generate(
            $proxyClass,
            '/original/ProxyArgumentErrors.php',
            $this->classSource($proxyClass, $method)
        ));

        foreach ([-1, 1] as $position) {
            $nativeError = null;
            $proxyError = null;

            try {
                (new $nativeClass)->argument($position);
            } catch (ValueError $error) {
                $nativeError = $error;
            }

            try {
                (new $proxyClass)->argument($position);
            } catch (ValueError $error) {
                $proxyError = $error;
            }

            $this->assertInstanceOf(ValueError::class, $nativeError);
            $this->assertInstanceOf(ValueError::class, $proxyError);
            $this->assertSame($nativeError->getMessage(), $proxyError->getMessage());
        }
    }

    #[DataProvider('unsupportedSourceProvider')]
    public function testRejectsSourceThatCannotBeProxiedSafely(string $method, string $message): void
    {
        $className = $this->className();
        $source = $this->classSource($className, $method);

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage($message);

        $this->generate($className, '/original/Unsupported.php', $source);
    }

    public static function unsupportedSourceProvider(): array
    {
        return [
            'reference return' => [
                <<<'PHP'
    public function &target(): mixed
    {
        static $value;

        return $value;
    }
PHP,
                'methods that return by reference cannot be intercepted safely',
            ],
            'call_user_func' => [
                <<<'PHP'
    public function target(): int
    {
        return call_user_func('func_num_args');
    }
PHP,
                'indirect calls to func_num_args() cannot preserve the original method frame',
            ],
            'call_user_func_array' => [
                <<<'PHP'
    public function target(string $value): string
    {
        return call_user_func_array('func_get_arg', [0]);
    }
PHP,
                'indirect calls to func_get_arg() cannot preserve the original method frame',
            ],
            'unpacked num args' => [
                <<<'PHP'
    public function target(): int
    {
        return func_num_args(...[]);
    }
PHP,
                'calls to func_num_args() cannot use argument unpacking',
            ],
            'unpacked get args' => [
                <<<'PHP'
    public function target(): array
    {
        return func_get_args(...[]);
    }
PHP,
                'calls to func_get_args() cannot use argument unpacking',
            ],
            'unpacked get arg' => [
                <<<'PHP'
    public function target(string $value): string
    {
        return func_get_arg(...[0]);
    }
PHP,
                'calls to func_get_arg() cannot use argument unpacking',
            ],
        ];
    }

    public function testRejectsSourcesWithMultipleNamedClassLikes(): void
    {
        $className = $this->className();
        $source = $this->classSource($className, <<<'PHP'
    public function target(): string
    {
        return 'target';
    }
PHP) . "\nclass AnotherNamedClass {}\n";

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('contains multiple named classes, interfaces, traits, or enums');

        $this->generate($className, '/original/Multiple.php', $source);
    }

    /**
     * Generate, load, and return a unique proxied class.
     */
    private function proxyClass(string $method, string $aspect = 'GeneratedAspect', string $rule = '*'): string
    {
        $className = $this->className();
        $source = $this->classSource($className, $method);
        $generated = $this->generate($className, '/original/' . class_basename($className) . '.php', $source, $aspect, $rule);

        $this->evaluate($generated);

        return $className;
    }

    /**
     * Generate proxy source for one class or trait.
     */
    private function generate(
        string $className,
        string $sourcePath,
        string $source,
        string $aspect = 'GeneratedAspect',
        string $rule = '*'
    ): string {
        $target = $rule === '*' ? $className : $className . '::' . $rule;
        AspectCollector::setAround($aspect, [$target]);

        if (! AstVisitorRegistry::exists(ProxyCallVisitor::class)) {
            AstVisitorRegistry::insert(ProxyCallVisitor::class);
        }

        $generated = (new Ast)->proxy($className, $sourcePath, $source);

        if ($aspect === 'GeneratedAspect') {
            AspectCollector::forgetAspect($aspect);
        }

        return $generated;
    }

    /**
     * Evaluate generated PHP code in the current process.
     */
    private function evaluate(string $code): void
    {
        eval(substr($code, strlen('<?php')));
    }

    /**
     * Build source containing one class.
     */
    private function classSource(string $className, string $method): string
    {
        $separator = strrpos($className, '\\');
        $namespace = substr($className, 0, $separator);
        $shortName = substr($className, $separator + 1);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

class {$shortName}
{
{$method}
}
PHP;
    }

    /**
     * Build source containing one trait.
     */
    private function traitSource(string $traitName, string $method): string
    {
        $separator = strrpos($traitName, '\\');
        $namespace = substr($traitName, 0, $separator);
        $shortName = substr($traitName, $separator + 1);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

trait {$shortName}
{
{$method}
}
PHP;
    }

    /**
     * Build a process-unique class name for evaluated source.
     */
    private function className(string $prefix = 'GeneratedProxy'): string
    {
        return __NAMESPACE__ . '\Fixtures\\' . $prefix . bin2hex(random_bytes(6)) . '\Target';
    }
}

class MutatingArgumentsAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $proceedingJoinPoint->arguments['keys']['value'] += 10;
        $proceedingJoinPoint->arguments['keys']['rest'][0] .= '-aspect';

        return $proceedingJoinPoint->process();
    }
}

#[Attribute]
class ProxyMethodAttribute
{
}

class NativeClosureDescriptorFixture
{
    public function descriptor(): string
    {
        return (fn (): string => __FUNCTION__)();
    }

    public function nestedDescriptor(): string
    {
        return (fn (): string => (fn (): string => __FUNCTION__)())();
    }
}
