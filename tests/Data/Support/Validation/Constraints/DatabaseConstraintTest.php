<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation\Constraints;

use Hypervel\Data\Support\Validation\Constraints\WhereConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereInConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNotConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNotInConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNotNullConstraint;
use Hypervel\Data\Support\Validation\Constraints\WhereNullConstraint;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseConstraintTest extends TestCase
{
    /**
     * Test scalar constraints apply to native database rules.
     */
    #[DataProvider('databaseRules')]
    public function testAppliesScalarConstraints(Exists|Unique $rule, string $expected): void
    {
        (new WhereConstraint('status', 'active'))->apply($rule);
        (new WhereNotConstraint('role', 'admin'))->apply($rule);
        (new WhereNullConstraint('deleted_at'))->apply($rule);
        (new WhereNotNullConstraint('verified_at'))->apply($rule);

        $this->assertSame($expected, (string) $rule);
    }

    /**
     * Test callback and set constraints register native query callbacks.
     */
    #[DataProvider('databaseRuleObjects')]
    public function testAppliesCallbackConstraints(Exists|Unique $rule): void
    {
        (new WhereConstraint(static fn (): null => null))->apply($rule);
        (new WhereInConstraint('status', ['active', 'pending']))->apply($rule);
        (new WhereNotInConstraint('role', ['admin', 'owner']))->apply($rule);

        $this->assertCount(3, $rule->queryCallbacks());
    }

    /**
     * Test constraints resolve external references at application time.
     */
    public function testResolvesExternalReferences(): void
    {
        $rule = new Exists('users', 'id');

        (new WhereConstraint(
            new DatabaseConstraintExternalReference('status'),
            new DatabaseConstraintExternalReference('active'),
        ))->apply($rule);

        $this->assertSame('exists:users,id,status,"active"', (string) $rule);
    }

    /**
     * Provide native database rules and their serialized scalar constraints.
     */
    public static function databaseRules(): iterable
    {
        yield [
            new Exists('users', 'id'),
            'exists:users,id,status,"active",role,"!admin",deleted_at,"NULL",verified_at,"NOT_NULL"',
        ];

        yield [
            new Unique('users', 'email'),
            'unique:users,email,NULL,id,status,"active",role,"!admin",deleted_at,"NULL",verified_at,"NOT_NULL"',
        ];
    }

    /**
     * Provide native database rule objects.
     */
    public static function databaseRuleObjects(): iterable
    {
        yield [new Exists('users', 'id')];
        yield [new Unique('users', 'email')];
    }
}

class DatabaseConstraintExternalReference implements ExternalReference
{
    public function __construct(protected mixed $value)
    {
    }

    /**
     * Resolve the referenced value.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }
}
