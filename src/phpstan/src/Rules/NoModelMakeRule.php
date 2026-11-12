<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\ConnectionName;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Properties\ModelHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ReflectionException;

use function array_all;

/**
 * Catches inefficient instantiation of models using Model::make().
 *
 * For example:
 * User::make()
 *
 * It is functionally equivalent to simply use the constructor:
 * new User()
 *
 * Unlike upstream, the rule reports only models whose construction it can show is unchanged. make() also
 * builds the model's query, whose global scopes can change the model it makes (an extend() calling
 * withAttributes()), and pins the model to the query's connection, while the constructor leaves a default
 * connection to resolve later. So it reports plain models with a fixed connection, made on an exact class.
 *
 * @implements Rule<StaticCall>
 */
#[Conditional(attribute: new AutowiredService, if: '%noModelMake%')]
class NoModelMakeRule implements Rule
{
    /**
     * The model methods, besides its query's, that make() runs differently from the constructor.
     */
    private const array CONSTRUCTION_METHODS = [
        '__construct',
        'newInstance',
        'initializeModelAttributes',
        'getConnectionName',
        'setConnection',
        'getConnection',
        'resolveConnection',
    ];

    /**
     * Whether make() creates each model as its constructor does, by class name.
     *
     * @var array<string, bool>
     */
    private array $plainModels = [];

    /**
     * Create a new no model make rule instance.
     */
    public function __construct(
        private ModelRuleHelper $modelRuleHelper,
        private ModelHelper $modelHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * Report make() calls on models that the constructor could create instead.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name;

        if (! $name instanceof Identifier) {
            return [];
        }

        if ($name->toLowerString() !== 'make') {
            return [];
        }

        if (! $this->isCalledOnModel($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message("Called 'Model::make()' which performs unnecessary work, use 'new Model()'.")
                ->identifier('hypervel.noModelMake')
                ->line($node->getStartLine())
                ->file($scope->getFile(), $scope->getFileDescription())
                ->build(),
        ];
    }

    /**
     * Was the expression called on models that make() creates as their constructors do?
     */
    protected function isCalledOnModel(StaticCall $call, Scope $scope): bool
    {
        $classes = $this->modelRuleHelper->exactCalledClasses($call, $scope);

        return $classes !== null && array_all(
            $classes,
            fn (ClassReflection $class): bool => $this->plainModels[$class->getName()] ??= $this->makesLikeConstructor($class),
        );
    }

    /**
     * Determine whether make() creates the model as its constructor does.
     *
     * The model must build plain queries, construct and resolve its connection as Model does, and have a
     * fixed connection other than a read connection, whose writable name make() would pin instead.
     */
    private function makesLikeConstructor(ClassReflection $class): bool
    {
        if (! $class->is(Model::class)
            || $class->hasNativeMethod('make')
            || ! $this->modelRuleHelper->buildsPlainQueries($class)
            || ! $this->modelRuleHelper->usesModelMethods($class, self::CONSTRUCTION_METHODS)) {
            return false;
        }

        try {
            $connection = $this->modelHelper->getInstance($class)->getConnectionName();
        } catch (ReflectionException) {
            return false;
        }

        return $connection !== null && $connection !== '' && ! ConnectionName::parse($connection)->isRead();
    }
}
