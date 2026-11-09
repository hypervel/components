<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Attributes\Appends;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Properties\ModelHelper;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\PHPStan\Reflection\SourceFiles;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ReflectionException;

use function array_map;
use function array_values;
use function sprintf;
use function strcasecmp;

/**
 * This rule validates that the properties a model appends are computed properties,
 * which serialization reads through a get accessor or a class cast.
 *
 * Unlike upstream, the rule reads both `$appends` and `#[Appends]`, and checks each concrete
 * model with the appends it inherits. A database column can be appended when a get accessor
 * or class cast computes it, while a set-only accessor does not make a property computed.
 *
 * @implements Rule<InClassNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkModelAppends%')]
class ModelAppendsRule implements Rule
{
    /**
     * The model methods that decide how serialization reads appended properties.
     */
    private const array SERIALIZATION_METHODS = [
        'toArray',
        'attributesToArray',
        'getArrayableAppends',
        'mutateAttributeForArray',
        'mutateAttribute',
    ];

    /**
     * Create a new model appends rule instance.
     */
    public function __construct(
        private ModelHelper $modelHelper,
        private ModelRuleHelper $modelRuleHelper,
        private InitializerExprTypeResolver $initializerExprTypeResolver,
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Report appended properties that serialization cannot compute.
     *
     * @param DependencyTracker&Scope $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! $classReflection->is(Model::class)
            || $classReflection->isAbstract()
            || ! $this->modelRuleHelper->usesModelMethods($classReflection, self::SERIALIZATION_METHODS)) {
            return [];
        }

        // A model the runtime cannot instantiate has no known casts.
        try {
            $this->modelHelper->getInstance($classReflection);
        } catch (ReflectionException) {
            return [];
        }

        $this->modelRuleHelper->trackModelSources($classReflection, $scope);

        $errors = [];

        foreach ($this->appendedProperties($classReflection, $node, $scope) as [$name, $line, $declaration]) {
            if ($this->modelRuleHelper->isComputedProperty($classReflection, $name)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message($classReflection->hasProperty($name)
                ? sprintf("Property '%s' is not a computed property, remove from %s.", $name, $declaration)
                : sprintf("Property '%s' does not exist in model.", $name))
                ->identifier('hypervel.modelAppends')
                ->line($line)
                ->build();
        }

        return $errors;
    }

    /**
     * Get each property the model appends, with the line to report it at and the declaration naming it.
     *
     * Like HasAttributes::initializeHasAttributes(), the #[Appends] properties are added to those of $appends.
     * A parent's or trait's appends are reported at the model, which can define what they need. Reflection
     * reparses expressions without their positions, so the model's own declarations are read from its source.
     *
     * @param DependencyTracker&Scope $scope
     * @return list<array{string, int, string}>
     */
    private function appendedProperties(ClassReflection $classReflection, InClassNode $node, Scope $scope): array
    {
        $class = $node->getOriginalNode();
        $properties = [];
        $property = $classReflection->getNativeReflection()->getProperty('appends');

        if ($property->hasDefaultValue()) {
            $declaringClass = $property->getDeclaringClass();
            $declared = $declaringClass->getName() === $classReflection->getName();
            $context = InitializerExprContext::fromClass($declaringClass->getName(), $declaringClass->getFileName() ?: null);
            $default = $declared ? $this->modelRuleHelper->declaredPropertyDefault($class, 'appends') : $property->getDefaultValueExpression();

            foreach ($this->listedNames($default === null ? [] : [$default], $context) as [$name, $expression]) {
                $properties[] = [$name, $declared ? $expression->getStartLine() : $node->getStartLine(), '$appends'];
            }
        }

        $attribute = ReflectionHelper::findClassAttribute($classReflection, Appends::class);

        if ($attribute !== null) {
            $source = $attribute[2];

            foreach (SourceFiles::forAttributeArguments($this->reflectionProvider, $source, Appends::class) as $file) {
                if ($file !== $scope->getFile()) {
                    $scope->trackFileDependency($file);
                }
            }

            $declared = $source->getName() === $classReflection->getName();
            $arguments = $declared
                ? array_map(static fn (Arg $argument): Expr => $argument->value, $this->findAttributeNode($class)->args ?? [])
                : array_values($source->getNativeReflection()->getAttributes(Appends::class)[0]->getArgumentsExpressions());

            foreach ($this->listedNames($arguments, InitializerExprContext::fromClassReflection($source)) as [$name, $expression]) {
                $properties[] = [$name, $declared ? $expression->getStartLine() : $node->getStartLine(), '#[Appends]'];
            }
        }

        return $properties;
    }

    /**
     * Find the #[Appends] attribute in the class's source.
     */
    private function findAttributeNode(ClassLike $class): ?Attribute
    {
        foreach ($class->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if (strcasecmp($attribute->name->toString(), Appends::class) === 0) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    /**
     * Get the constant names a $appends default or the #[Appends] arguments list, each with the expression naming it.
     *
     * Like the Appends constructor, a first array argument holds the names, and otherwise each argument is one.
     *
     * @param list<Expr> $expressions
     * @return list<array{string, Expr}>
     */
    private function listedNames(array $expressions, InitializerExprContext $context): array
    {
        $first = $expressions[0] ?? null;

        if ($first instanceof Array_) {
            $names = [];

            // An unpacked item lists the names of the array it spreads.
            foreach ($first->items as $item) {
                $names = [...$names, ...($item->unpack ? $this->arrayNames($item->value, $context) : $this->constantNames($item->value, $context))];
            }

            return $names;
        }

        if ($first !== null && $this->initializerExprTypeResolver->getType($first, $context)->isArray()->yes()) {
            return $this->arrayNames($first, $context);
        }

        $names = [];

        foreach ($expressions as $expression) {
            $names = [...$names, ...$this->constantNames($expression, $context)];
        }

        return $names;
    }

    /**
     * Get the constant names an array expression holds, each with that expression.
     *
     * @return list<array{string, Expr}>
     */
    private function arrayNames(Expr $expression, InitializerExprContext $context): array
    {
        $names = [];

        foreach ($this->initializerExprTypeResolver->getType($expression, $context)->getConstantArrays() as $array) {
            foreach ($array->getValueTypes() as $valueType) {
                foreach ($valueType->getConstantStrings() as $name) {
                    $names[] = [$name->getValue(), $expression];
                }
            }
        }

        return $names;
    }

    /**
     * Get the constant names an expression can be, each with that expression.
     *
     * @return list<array{string, Expr}>
     */
    private function constantNames(Expr $expression, InitializerExprContext $context): array
    {
        $names = [];

        foreach ($this->initializerExprTypeResolver->getType($expression, $context)->getConstantStrings() as $name) {
            $names[] = [$name->getValue(), $expression];
        }

        return $names;
    }
}
