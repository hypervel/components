<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\PHPStan\Concerns\CollectsTranslationLookups;
use Hypervel\Support\Facades\Lang;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ObjectType;

use function in_array;

/**
 * @phpstan-import-type TranslationLookup from CollectsTranslationLookups
 *
 * @implements Collector<StaticCall, TranslationLookup>
 */
#[AutowiredService]
class UsedTranslationFacadeCollector implements Collector
{
    use CollectsTranslationLookups;

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * Collect the translation looked up through the Lang facade.
     *
     * @param StaticCall $node
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

        $class = $node->class;

        if (! $class instanceof Name) {
            return null;
        }

        $class = $scope->resolveName($class);

        if (! (new ObjectType(Lang::class))->isSuperTypeOf(new ObjectType($class))->yes()) {
            return null;
        }

        return $name->name === 'get'
            ? $this->translationLookup($node, choice: false, acceptsFallback: true, scope: $scope)
            : $this->translationLookup($node, choice: true, acceptsFallback: false, scope: $scope);
    }
}
