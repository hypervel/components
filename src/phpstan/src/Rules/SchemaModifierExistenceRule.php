<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\Database\Schema\ForeignKeyDefinition;
use Hypervel\Database\Schema\IndexDefinition;
use Hypervel\PHPStan\Reflection\DynamicMethodReflection;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\RegisteredRule;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

use function array_any;
use function sprintf;

/**
 * Report an unknown modifier, such as nulable(), on a schema column, index or foreign key definition.
 *
 * A definition is a fluent object, which accepts any method as an attribute, but the schema grammars
 * ignore attributes they do not know, so a misspelled modifier would silently do nothing.
 *
 * @implements Rule<MethodCall>
 */
#[RegisteredRule(level: 2)]
class SchemaModifierExistenceRule implements Rule
{
    /**
     * The schema definitions whose undeclared methods are reported.
     */
    private const array DEFINITIONS = [
        ColumnDefinition::class,
        ForeignKeyDefinition::class,
        IndexDefinition::class,
    ];

    /**
     * Create the rule.
     */
    public function __construct(
        #[AutowiredParameter]
        private bool $reportMagicMethods,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Report a call to a schema definition method that is neither declared nor a macro.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan's own method check reports an undefined method of a class with __call() only in this mode.
        if (! $this->reportMagicMethods || ! $node->name instanceof Identifier) {
            return [];
        }

        $type = $scope->getType($node->var);
        $classes = $type->getObjectClassReflections();
        $methodName = $node->name->toString();

        if ($classes === []) {
            return [];
        }

        // Every possible receiver must be a schema definition whose method FluentMethodsExtension synthesizes,
        // because the definition neither declares it nor has it as a macro.
        foreach ($classes as $class) {
            if (! array_any(self::DEFINITIONS, static fn (string $className): bool => $class->is($className))
                || ! $class->hasMethod($methodName)
                || ! $class->getMethod($methodName, $scope)->getPrototype() instanceof DynamicMethodReflection) {
                return [];
            }
        }

        // A subclass may declare the method, so a call guarded by method_exists() is valid.
        $methodExists = new FuncCall(new FullyQualified('method_exists'), [new Arg($node->var), new Arg(new String_($methodName))]);

        if ($scope->getType($methodExists)->isTrue()->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Call to an undefined method %s::%s().',
                $type->describe(VerbosityLevel::typeOnly()),
                $methodName,
            ))
                ->identifier('method.notFound')
                ->tip('The schema grammars ignore unknown modifiers.')
                ->build(),
        ];
    }
}
