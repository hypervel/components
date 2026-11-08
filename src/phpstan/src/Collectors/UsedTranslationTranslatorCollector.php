<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Contracts\Translation\Translator;
use Hypervel\PHPStan\Concerns\CollectsTranslationLookups;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

use function in_array;

/**
 * @phpstan-import-type TranslationLookup from CollectsTranslationLookups
 *
 * @implements Collector<MethodCall, TranslationLookup>
 */
#[AutowiredService]
class UsedTranslationTranslatorCollector implements Collector
{
    use CollectsTranslationLookups;

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Collect the translation looked up through a translator.
     *
     * @param MethodCall $node
     *
     * @return null|TranslationLookup
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $name = $node->name;

        if (! $name instanceof Identifier) {
            return null;
        }

        if (! in_array($name->name, ['get', 'choice'], true)) {
            return null;
        }

        $class = $node->var;

        // Upstream matches the concrete translator. Hypervel code injects the contract,
        // which trans() also returns, and the concrete translator implements it.
        if (! (new ObjectType(Translator::class))->isSuperTypeOf($scope->getType($class))->yes()) {
            return null;
        }

        return $name->name === 'get'
            ? $this->translationLookup($node, choice: false, acceptsFallback: true, scope: $scope)
            : $this->translationLookup($node, choice: true, acceptsFallback: false, scope: $scope);
    }
}
