<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Composer\InstalledVersions;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\ServiceProvider;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

use function array_all;
use function array_any;
use function in_array;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;

/**
 * Reports a service provider registering a model's observers with observe(), where the
 * Laravel convention is #[ObservedBy] on the model.
 *
 * Only an unconditional statement in register() or boot() with a constant list of observer
 * classes counts, for a model the application declares rather than an installed package.
 *
 * @implements Rule<InClassMethodNode>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkObserverAttributes%')]
class ObserverAttributeRule implements Rule
{
    /**
     * Create a new observer attribute rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * Report the provider method's observe() registrations.
     *
     * @param InClassMethodNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! in_array(strtolower($node->getMethodReflection()->getName()), ['register', 'boot'], true)
            || ! $node->getClassReflection()->is(ServiceProvider::class)
        ) {
            return [];
        }

        $errors = [];

        foreach ($node->getOriginalNode()->stmts ?? [] as $statement) {
            if (! $statement instanceof Expression) {
                continue;
            }

            $call = $statement->expr;

            if (! $call instanceof StaticCall
                || ! $call->class instanceof Name
                || $call->class->isSpecialClassName()
                || ! $call->name instanceof Identifier
                || $call->name->toLowerString() !== 'observe'
            ) {
                continue;
            }

            $model = $this->getModel($call->class, $scope);
            $observers = $call->getArg('classes', 0);

            if ($model === null
                || $observers === null
                || array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack)
                || ! $this->isClassStringList($scope->getType($observers->value))
            ) {
                continue;
            }

            // Composer's installed packages decide which models are dependencies.
            $this->runtimeInputs->track($scope);

            if ($this->isDependency($model)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf('%s registers its observers with observe() in a service provider.', $model->getName()))
                ->identifier('hypervel.observerAttribute')
                ->line($call->getStartLine())
                ->tip('By convention, list them in #[ObservedBy] on the model. Subclasses inherit attribute observers, so keep observe() with an ignore when they are meant for this class only.')
                ->build();
        }

        return $errors;
    }

    /**
     * Get the model whose own Model::observe() the call runs.
     */
    private function getModel(Name $name, Scope $scope): ?ClassReflection
    {
        $className = $scope->resolveName($name);

        if (! $this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $model = $this->reflectionProvider->getClass($className);

        if (! $model->is(Model::class) || $model->getNativeMethod('observe')->getDeclaringClass()->getName() !== Model::class) {
            return null;
        }

        return $model;
    }

    /**
     * Determine whether an installed Composer package declares the class, so the application cannot add an attribute to it.
     *
     * Packages are compared by their real directories: a path repository's package can be outside the vendor directory.
     */
    private function isDependency(ClassReflection $class): bool
    {
        $file = $class->getFileName();

        if ($file === null) {
            return true;
        }

        $file = realpath($file) ?: $file;

        foreach (InstalledVersions::getAllRawData() as $installed) {
            foreach ($installed['versions'] as $name => $package) {
                if ($name === $installed['root']['name'] || ! isset($package['install_path'])) {
                    continue;
                }

                $directory = realpath($package['install_path']);

                if ($directory !== false && str_starts_with($file, $directory . DIRECTORY_SEPARATOR)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the type is a constant class-string or a constant list of them.
     */
    private function isClassStringList(Type $type): bool
    {
        $values = $type->isConstantArray()->yes() ? $type->getIterableValueType() : $type;
        $strings = $values->getConstantStrings();

        return $strings !== [] && array_all($strings, static fn (ConstantStringType $string): bool => $string->isClassString()->yes());
    }
}
