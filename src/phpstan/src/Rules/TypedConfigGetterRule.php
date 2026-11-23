<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Internal\ConfigHelper;
use Hypervel\PHPStan\Support\ConfigParser;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

use function array_any;
use function sprintf;
use function strtolower;

/**
 * Reports a config read whose value always has one type that a typed getter returns.
 *
 * Only an application's configuration is known: an application using a package can
 * give the keys the package reads other values.
 *
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkTypedConfigGetters%')]
class TypedConfigGetterRule implements Rule
{
    /**
     * Create a new typed config getter rule instance.
     */
    public function __construct(
        private ConfigHelper $configHelper,
        private ConfigParser $configParser,
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
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
     * Report an untyped read of a config value with one type.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $isHelper = $node instanceof FuncCall
            && $node->name instanceof Name
            && strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? '') === 'config';

        if (! $isHelper && $this->configHelper->getMethodName($node, $scope) !== 'get') {
            return [];
        }

        $key = $node->getArg('key', 0);

        if ($key === null || array_any($node->getArgs(), static fn (Arg $argument): bool => $argument->unpack)) {
            return [];
        }

        $keyType = $scope->getType($key->value);

        if (! $keyType->isString()->yes() || ! $keyType->isConstantScalarValue()->yes()) {
            return [];
        }

        // The booted application decides whether this is a package's analysis.
        $this->runtimeInputs->track($scope);

        if ($this->runtimeInputs->isPackageApplication()) {
            return [];
        }

        $types = [];

        foreach ($keyType->getConstantStrings() as $constantString) {
            $value = $this->configParser->getValue($constantString->getValue(), $scope);

            // A default applies to an absent key, so only a present key's value decides the type.
            if ($value === null || ! $value[0]->yes()) {
                return [];
            }

            $types[] = $value[1];
        }

        $type = TypeCombinator::union(...$types);

        [$getter, $description] = match (true) {
            $type->isString()->yes() => ['string', 'a string'],
            $type->isInteger()->yes() => ['integer', 'an integer'],
            $type->isFloat()->yes() => ['float', 'a float'],
            $type->isBoolean()->yes() => ['boolean', 'a boolean'],
            $type->isArray()->yes() => ['array', 'an array'],
            default => [null, null],
        };

        if ($getter === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Config key %s always holds %s, so read it with %s().',
                $keyType->describe(VerbosityLevel::precise()),
                $description,
                $getter,
            ))
                ->identifier('hypervel.typedConfigGetter')
                ->tip(sprintf(
                    'Use config()->%1$s(), Config::%1$s() or the repository\'s %1$s(), which return the value\'s type and throw if the configuration changes it.',
                    $getter,
                ))
                ->build(),
        ];
    }
}
