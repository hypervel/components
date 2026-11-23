<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Support\ArrKeyLookup;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

use function count;
use function in_array;
use function strtolower;

/**
 * Reports Arr::get() and Arr::pull() keys that never exist, through PHPStan's own offset check.
 * Upstream also reports calls that pass a default for the missing key.
 *
 * @implements Rule<StaticCall>
 */
#[AutowiredService]
class ArrKeyExistenceRule implements Rule
{
    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * Report the missing offset of an Arr::get() or Arr::pull() key that never exists.
     *
     * @param NodeCallbackInvoker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || ! $node->class instanceof Name) {
            return [];
        }

        $methodName = strtolower($node->name->name);

        if (! in_array($methodName, ['get', 'pull'], true) || ! ArrKeyLookup::appliesTo($scope, $node, [$methodName, ...ArrKeyLookup::GET_METHODS])) {
            return [];
        }

        $arrayArg = $node->getArg('array', 0);
        $keyArg = $node->getArg('key', 1);

        // A default means the caller expects the key may be missing.
        if ($arrayArg === null || $keyArg === null || $node->getArg('default', 2) !== null) {
            return [];
        }

        $lookups = ArrKeyLookup::find($scope, $arrayArg->value, $keyArg->value) ?? [];

        foreach ($lookups as $candidates) {
            foreach ($candidates as [$found]) {
                if (! $found->no()) {
                    return [];
                }
            }
        }

        foreach ($lookups as $candidates) {
            // The last candidate is the "dot" path when there is one.
            [, $missing] = $candidates[count($candidates) - 1];
            $missing->setAttributes($node->getAttributes());
            $scope->invokeNodeCallback($missing);
        }

        return [];
    }
}
