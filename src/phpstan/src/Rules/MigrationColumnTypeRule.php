<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Schema\Blueprint;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports schema columns whose types Hypervel's conventions replace.
 *
 * @implements Rule<MethodCall>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkMigrationColumnTypes%')]
class MigrationColumnTypeRule implements Rule
{
    /**
     * The message and tip for each reported Blueprint method, by lowercase name.
     */
    private const array METHODS = [
        'enum' => [
            'enum() creates a database enum, so adding a value needs a migration.',
            'Use a string column with a PHP enum cast.',
        ],
        'set' => [
            'set() creates a database set, so adding a value needs a migration.',
            'Store the values in a jsonb() column cast with AsEnumCollection, or in a related table.',
        ],
        'json' => [
            "json() creates a json column, which PostgreSQL can't index or compare like jsonb.",
            'Use jsonb() for JSON the database queries, indexes or validates, or text() for a value only read back whole. jsonb() uses the appropriate type for each database.',
        ],
    ];

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Report a column type the conventions replace.
     *
     * @param MethodCall $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || ! isset(self::METHODS[$node->name->toLowerString()])) {
            return [];
        }

        $method = $scope->getMethodReflection($scope->getType($node->var), $node->name->toString());

        if ($method === null || $method->getDeclaringClass()->getName() !== Blueprint::class) {
            return [];
        }

        [$message, $tip] = self::METHODS[$node->name->toLowerString()];

        return [
            RuleErrorBuilder::message($message)
                ->identifier('hypervel.migrationColumnType')
                ->tip($tip)
                ->build(),
        ];
    }
}
