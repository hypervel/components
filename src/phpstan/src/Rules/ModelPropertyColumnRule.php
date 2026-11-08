<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use Hypervel\PHPStan\Reflection\EloquentBuilderMethodReflection;
use Hypervel\PHPStan\Reflection\ForwardedMethodReflection;
use Hypervel\PHPStan\Types\ModelProperty\GenericModelPropertyType;
use Hypervel\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

use function count;
use function in_array;
use function preg_split;
use function sprintf;
use function str_starts_with;
use function substr;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Check the column arguments of the pagination methods and pluck(), and the columns a dynamic where names,
 * against the model's properties.
 *
 * The other query methods take model-property parameters from the stubs. The pagination methods and pluck()
 * cannot be stubbed: their returns name the collections and paginators, whose declarations fail stub
 * validation. A dynamic where has no declaration at all.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkModelProperties%')]
class ModelPropertyColumnRule implements Rule
{
    /**
     * The column parameter of each checked method, and the classes whose implementation of it is checked.
     *
     * @var array<string, array{string, int, list<class-string>}>
     */
    private const array METHODS = [
        'paginate' => ['columns', 1, [EloquentBuilder::class, BelongsToMany::class, HasOneOrManyThrough::class]],
        'simplepaginate' => ['columns', 1, [EloquentBuilder::class, BelongsToMany::class, HasOneOrManyThrough::class]],
        'cursorpaginate' => ['columns', 1, [EloquentBuilder::class, BelongsToMany::class, HasOneOrManyThrough::class]],
        'pluck' => ['column', 0, [EloquentBuilder::class]],
    ];

    /**
     * Create the rule.
     */
    public function __construct(
        private BuilderHelper $builderHelper,
        private ModelPropertyHelper $modelPropertyHelper,
        private ModelRuleHelper $modelRuleHelper,
        private RuleLevelHelper $ruleLevelHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report a column that is not a property of the queried model.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan also analyses a nullsafe call as a method call on the non-null object, so it is checked there once.
        if (! ($node instanceof MethodCall || $node instanceof StaticCall) || ! $node->name instanceof Identifier) {
            return [];
        }

        // Query\Builder::__call() matches the prefix case-sensitively.
        if (str_starts_with($node->name->toString(), 'where')) {
            return $this->checkDynamicWhere($node, $scope, $node->name->toString());
        }

        $methodName = $node->name->toLowerString();

        if (! isset(self::METHODS[$methodName])) {
            return [];
        }

        [$parameterName, $position, $implementations] = self::METHODS[$methodName];
        $argument = $node->getArg($parameterName, $position);

        if ($argument === null || $argument->unpack) {
            return [];
        }

        $callerType = $this->modelRuleHelper->callerType($node, $scope);
        $callerClasses = $callerType->getObjectClassReflections();

        if (count($callerClasses) !== 1) {
            return [];
        }

        [$modelType, $implementation] = $this->resolveCall($callerClasses[0], $callerType, $node->name->toString());

        if ($modelType === null || ! in_array($implementation, $implementations, strict: true)) {
            return [];
        }

        $property = new GenericModelPropertyType($modelType, $this->modelPropertyHelper);
        $columns = new ArrayType(new IntegerType, TypeCombinator::union($property, new ObjectType(Expression::class), new ConstantStringType('*')));

        // These are the types the upstream stubs declare. The relations take only a list natively.
        $expectedType = match (true) {
            $methodName === 'pluck' => TypeCombinator::union(new ObjectType(Expression::class), $property),
            $implementation === EloquentBuilder::class => TypeCombinator::union($columns, $property, new ConstantStringType('*')),
            default => $columns,
        };
        $argumentType = $scope->getType($argument->value);
        $accepts = $this->ruleLevelHelper->accepts($expectedType, $argumentType, $scope->isDeclareStrictTypes());

        if ($accepts->result) {
            return [];
        }

        $verbosity = VerbosityLevel::getRecommendedLevelByType($expectedType, $argumentType);

        return [
            RuleErrorBuilder::message(sprintf(
                'Parameter #%d $%s of %s %s::%s() expects %s, %s given.',
                $position + 1,
                $parameterName,
                $node instanceof StaticCall ? 'static method' : 'method',
                $callerType->describe(VerbosityLevel::typeOnly()),
                $node->name->toString(),
                $expectedType->describe($verbosity),
                $argumentType->describe($verbosity),
            ))->identifier('argument.type')->acceptsReasonsTip($accepts->reasons)->build(),
        ];
    }

    /**
     * Report a dynamic where, such as whereEmail(), naming a column that is not a property of the queried model.
     *
     * The forwarding extensions resolve every dynamic where, as Query\Builder::__call() runs it, so the method's
     * existence does not depend on the model.
     *
     * @return list<IdentifierRuleError>
     */
    private function checkDynamicWhere(MethodCall|StaticCall $node, Scope $scope, string $methodName): array
    {
        $callerType = $this->modelRuleHelper->callerType($node, $scope);
        $callerClasses = $callerType->getObjectClassReflections();

        // A native method, such as the builder's where(), is not a dynamic where.
        if (count($callerClasses) !== 1 || $callerClasses[0]->hasNativeMethod($methodName)) {
            return [];
        }

        $modelType = $this->modelRuleHelper->queriedModel($callerClasses[0], $callerType);

        // An unknown model may have any column.
        if ($modelType === null || $modelType->getObjectClassReflections() === []) {
            return [];
        }

        $method = $scope->getMethodReflection($callerType, $methodName)?->getPrototype();

        // Each forwarding hop, such as a relation's to its builder, wraps the synthesized method.
        while ($method instanceof ForwardedMethodReflection) {
            $method = $method->getForwardedMethod()->getPrototype();
        }

        if (! $method instanceof EloquentBuilderMethodReflection) {
            return [];
        }

        // Split like Query\Builder::dynamicWhere(); each segment names a column.
        $segments = preg_split('/(And|Or)(?=[A-Z])/', substr($methodName, 5), -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($segments === false ? [] : $segments as $segment) {
            $column = Str::snake($segment);

            if ($segment === 'And' || $segment === 'Or' || $this->modelPropertyHelper->acceptsPropertyName($modelType, $column)->yes()) {
                continue;
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    'Call to an undefined %s %s::%s().',
                    $node instanceof StaticCall ? 'static method' : 'method',
                    $callerType->describe(VerbosityLevel::typeOnly()),
                    $methodName,
                ))
                    ->identifier($node instanceof StaticCall ? 'staticMethod.notFound' : 'method.notFound')
                    ->tip(sprintf('Column %s is not a property of %s.', $column, $modelType->describe(VerbosityLevel::typeOnly())))
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * Resolve the queried model and the class whose method runs, following Relation::__call() and
     * Model::__call() to the model's builder.
     *
     * @return array{null|Type, null|string}
     */
    private function resolveCall(ClassReflection $callerClass, Type $callerType, string $methodName): array
    {
        $modelType = $this->modelRuleHelper->queriedModel($callerClass, $callerType);

        // A model's own method of that name is not forwarded.
        if ($modelType === null || ($callerClass->is(Model::class) && $callerClass->hasNativeMethod($methodName))) {
            return [null, null];
        }

        return [$modelType, $callerClass->hasNativeMethod($methodName)
            ? $callerClass->getNativeMethod($methodName)->getDeclaringClass()->getName()
            : $this->builderImplementation($modelType, $methodName)];
    }

    /**
     * Return the class whose method the model's builder runs.
     */
    private function builderImplementation(Type $modelType, string $methodName): ?string
    {
        $modelClasses = $modelType->getObjectClassNames();
        $builderClasses = count($modelClasses) === 1
            ? $this->builderHelper->determineBuilderClass($modelClasses[0], $modelType)?->getObjectClassReflections() ?? []
            : [];

        return count($builderClasses) === 1
            ? $builderClasses[0]->getNativeMethod($methodName)->getDeclaringClass()->getName()
            : null;
    }
}
