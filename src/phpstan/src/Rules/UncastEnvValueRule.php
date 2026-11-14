<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Env;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\LiteralArrayNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function count;
use function sprintf;
use function strtolower;

/**
 * Reports a config value that is an uncast env() read with a bool, int or float default.
 *
 * The environment holds strings, so the value has the default's type only while the variable
 * is unset. A read counts when its value reaches the config value unchanged: directly, through
 * an assignment, the branches of ?:, ?? and ternaries, or as another read's default. A read in a
 * condition or cast is converted, and values passed through other calls or variables are not
 * followed.
 *
 * @implements Rule<LiteralArrayNode>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkConfigEnvCasts%')]
class UncastEnvValueRule implements Rule
{
    /**
     * Create a new uncast env value rule instance.
     */
    public function __construct(
        private ConfigSourceResolver $configSourceResolver,
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return LiteralArrayNode::class;
    }

    /**
     * Report the array's uncast env values.
     *
     * @param LiteralArrayNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        $isConfigFile = null;

        foreach ($node->getItemNodes() as $itemNode) {
            $item = $itemNode->getArrayItem();

            if ($item === null || $item->unpack) {
                continue;
            }

            $itemScope = $itemNode->getScope();

            foreach ($this->findValueReads($item->value, $itemScope) as [$read, $reader]) {
                $default = $read->getArg('default', 1);
                $defaultType = $default === null ? null : $itemScope->getType($default->value);

                $type = match (true) {
                    $defaultType === null => null,
                    $defaultType->isBoolean()->yes() => 'a bool',
                    $defaultType->isInteger()->yes() => 'an int',
                    $defaultType->isFloat()->yes() => 'a float',
                    default => null,
                };

                if ($type === null) {
                    continue;
                }

                if ($isConfigFile === null) {
                    // The booted application decides the config directories and package config files.
                    $this->runtimeInputs->track($scope);

                    $isConfigFile = $this->configSourceResolver->isConfigFile($scope->getFile());
                }

                if (! $isConfigFile) {
                    return [];
                }

                $errors[] = RuleErrorBuilder::message(sprintf('%s can return a string, but its default is %s.', $reader, $type))
                    ->identifier('hypervel.uncastEnvValue')
                    ->line($read->getStartLine())
                    ->tip("Cast it in the config file to its default's type, such as (int) env('DB_PORT', 3306). When the variable holds a name or other text, use a string default instead.")
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * Find the env reads whose value the expression passes through unchanged, each with its reader's name.
     *
     * A read's default is its value while the variable is unset, so a read in that default counts too.
     *
     * @return list<array{FuncCall|StaticCall, string}>
     */
    private function findValueReads(Expr $expr, Scope $scope): array
    {
        if ($expr instanceof Assign) {
            return $this->findValueReads($expr->expr, $scope);
        }

        if ($expr instanceof Ternary) {
            return [...$this->findValueReads($expr->if ?? $expr->cond, $scope), ...$this->findValueReads($expr->else, $scope)];
        }

        if ($expr instanceof Coalesce) {
            return [...$this->findValueReads($expr->left, $scope), ...$this->findValueReads($expr->right, $scope)];
        }

        if (! ($expr instanceof FuncCall || $expr instanceof StaticCall)) {
            return [];
        }

        $reader = $this->getReaderName($expr, $scope);

        if ($reader === null) {
            return [];
        }

        $default = $expr->getArg('default', 1);

        return [[$expr, $reader], ...($default === null ? [] : $this->findValueReads($default->value, $scope))];
    }

    /**
     * Get the name of an env() or Env::get() read with its key, or null for any other call.
     */
    private function getReaderName(FuncCall|StaticCall $call, Scope $scope): ?string
    {
        if ($call instanceof FuncCall) {
            if (! $call->name instanceof Name || strtolower($this->reflectionProvider->resolveFunctionName($call->name, $scope) ?? '') !== 'env') {
                return null;
            }

            $reader = 'env';
        } else {
            if (! $call->class instanceof Name || ! $call->name instanceof Identifier || $call->name->toLowerString() !== 'get') {
                return null;
            }

            $className = $scope->resolveName($call->class);

            if (! $this->reflectionProvider->hasClass($className)) {
                return null;
            }

            $class = $this->reflectionProvider->getClass($className);

            // An override's body is unknown, so only Env's own get() counts.
            if (! $class->hasNativeMethod('get') || $class->getNativeMethod('get')->getDeclaringClass()->getName() !== Env::class) {
                return null;
            }

            $reader = $class->getName() . '::get';
        }

        $key = $call->getArg('key', 0);
        $keys = $key === null ? [] : $scope->getType($key->value)->getConstantStrings();

        return $reader . '(' . (count($keys) === 1 ? "'" . $keys[0]->getValue() . "'" : '') . ')';
    }
}
