<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Foundation\Events\Dispatchable as EventDispatchable;
use Hypervel\PHPStan\Rules\CheckDispatchArgumentTypesCompatibleWithClassConstructorRule;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Classes\InstantiationRule;
use PHPStan\Rules\Functions\CallCallablesRule;
use PHPStan\Rules\Methods\CallStaticMethodsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<Rule<Node>> */
class CheckDispatchArgumentTypesCompatibleWithClassConstructorRuleTest extends RuleTestCase
{
    private string $dispatchableClass;

    protected function getRule(): Rule
    {
        // RuleTestCase registers one rule; also run PHPStan's rules for the virtual calls.
        return new /** @implements Rule<Node> */ class(new CheckDispatchArgumentTypesCompatibleWithClassConstructorRule($this->createReflectionProvider(), $this->dispatchableClass), self::getContainer()->getByType(CallStaticMethodsRule::class), self::getContainer()->getByType(InstantiationRule::class), self::getContainer()->getByType(CallCallablesRule::class)) implements Rule {
            public function __construct(
                private CheckDispatchArgumentTypesCompatibleWithClassConstructorRule $dispatchRule,
                private CallStaticMethodsRule $staticCallsRule,
                private InstantiationRule $instantiationRule,
                private CallCallablesRule $callablesRule,
            ) {
            }

            public function getNodeType(): string
            {
                return Node::class;
            }

            public function processNode(Node $node, Scope $scope): array
            {
                return match (true) {
                    $node instanceof StaticCall => [...$this->dispatchRule->processNode($node, $scope), ...$this->staticCallsRule->processNode($node, $scope)],
                    $node instanceof New_ => $this->instantiationRule->processNode($node, $scope),
                    $node instanceof FuncCall => $this->callablesRule->processNode($node, $scope),
                    default => [],
                };
            }
        };
    }

    public function testJobDispatch(): void
    {
        $this->dispatchableClass = Dispatchable::class;

        $this->analyse([__DIR__ . '/data/job-dispatch.php'], [
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 0 parameters, 2 required.', 5],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 0 parameters, 2 required.', 6],
            // REMOVED: dispatchNow() cases. Hypervel's Dispatchable has no dispatchNow() method.
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 0 parameters, 2 required.', 7],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 1 parameter, 2 required.', 9],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 10],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 10],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, true given.', 11],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, false given.', 11],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 1 parameter, 2 required.', 13],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 14],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 14],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, true given.', 15],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, false given.', 15],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor invoked with 1 parameter, 2 required.', 17],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 18],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 18],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, true given.', 19],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, false given.', 19],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 21],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 21],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 22],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be instantiated without any parameters.', 25],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be instantiated without any parameters.', 27],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be instantiated without any parameters.', 29],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithoutConstructor does not have a constructor and must be instantiated without any parameters.', 32],
            ['Parameter $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 34],
            ['Parameter $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 34],
            ['Parameter $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 36],
            ['Parameter $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 36],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 38],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 38],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 40],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 40],
            // Hypervel: reported by PHPStan's static call check, which this test also runs for the forwarded calls.
            ["Call to static method dispatch() on an unknown class Hypervel\\Tests\\PHPStan\\Rules\\Data\\UnknownJob.\n    💡 Learn more at https://phpstan.org/user-guide/discovering-symbols", 42],
            ['Parameter #1 $foo of closure expects string, int given.', 45],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 49],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 49],
            ['Missing parameter $bar (int) in call to Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor.', 50],
            ['Parameter #1 $id of static method Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithOwnDispatch::dispatch() expects int, string given.', 56],
            ['Parameter #1 $id of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJobWithProtectedDispatch constructor expects string, int given.', 58],
            ['Parameter #1 $id of closure expects int, string given.', 60],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects string, int given.', 63],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelJob constructor expects int, string given.', 63],
            ['Parameter #1 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelChildJob constructor expects int, string given.', 91],
        ]);
    }

    public function testEventDispatch(): void
    {
        $this->dispatchableClass = EventDispatchable::class;

        $this->analyse([__DIR__ . '/data/event-dispatch.php'], [
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor invoked with 0 parameters, 2 required.', 5],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor invoked with 1 parameter, 2 required.', 7],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string, int given.', 8],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects int, string given.', 8],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string, true given.', 9],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects int, false given.', 9],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string, int given.', 11],
            ['Parameter #2 $bar of class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects int, string given.', 11],
            ['Parameter #1 $foo of class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor expects string, int given.', 12],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelEventWithoutConstructor does not have a constructor and must be instantiated without any parameters.', 15],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelEventWithoutConstructor does not have a constructor and must be instantiated without any parameters.', 18],
            ['Class Hypervel\Tests\PHPStan\Rules\Data\HypervelEvent constructor invoked with 1 parameter, 2 required.', 21],
            ['Parameter #1 $count of closure expects int, string given.', 25],
        ]);
    }
}
