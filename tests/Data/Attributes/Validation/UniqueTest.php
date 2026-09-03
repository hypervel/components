<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\Constraints\WhereConstraint;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Unique as UniqueRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class UniqueTest extends TestCase
{
    /**
     * Test a unique attribute requires a table or native rule.
     */
    public function testRequiresTableOrRule(): void
    {
        $this->expectException(CannotBuildValidationRule::class);

        new Unique;
    }

    /**
     * Test an explicitly supplied native rule is preserved.
     */
    public function testReturnsProvidedRule(): void
    {
        $rule = new UniqueRule('users', 'email');

        $this->assertSame(
            [$rule],
            (new RuleDenormalizer)->execute(new Unique(rule: $rule), ValidationPath::create()),
        );
    }

    /**
     * Test externally resolved parameters and constraints configure the native rule.
     */
    public function testBuildsConfiguredRule(): void
    {
        $attribute = new Unique(
            table: new UniqueExternalReference('users'),
            column: new UniqueExternalReference('email'),
            connection: new UniqueExternalReference('tenant'),
            ignore: new UniqueExternalReference(69),
            ignoreColumn: new UniqueExternalReference('uuid'),
            withoutTrashed: new UniqueExternalReference(true),
            deletedAtColumn: new UniqueExternalReference('removed_at'),
            where: new WhereConstraint('status', 'active'),
        );

        $rule = (new RuleDenormalizer)->execute($attribute, ValidationPath::create())[0];

        $this->assertSame(
            'unique:tenant.users,email,"69",uuid,removed_at,"NULL",status,"active"',
            (string) $rule,
        );
    }

    /**
     * Test parsed parameters build a native unique rule.
     */
    public function testCreatesFromParsedParameters(): void
    {
        $rule = (new RuleDenormalizer)->execute(
            Unique::create('users', 'email'),
            ValidationPath::create(),
        )[0];

        $this->assertSame('unique:users,email,NULL,id', (string) $rule);
    }

    /**
     * Test an explicit null column uses the native default sentinel.
     */
    public function testUsesDefaultColumnForExplicitNull(): void
    {
        $rule = (new Unique('users', null))->getRule(ValidationPath::create());

        $this->assertSame('unique:users,NULL,NULL,id', (string) $rule);
    }

    /**
     * Test integer zero remains a valid ignored identifier.
     */
    public function testIgnoresZeroIdentifier(): void
    {
        $rule = (new Unique('users', 'email', ignore: 0))->getRule(ValidationPath::create());

        $this->assertSame('unique:users,email,"0",id', (string) $rule);
    }

    /**
     * Test invalid externally resolved parameters fail clearly.
     */
    #[DataProvider('invalidResolvedParameters')]
    public function testRejectsInvalidResolvedParameter(Unique $attribute, string $message): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIs($message);

        $attribute->getRule(ValidationPath::create());
    }

    /**
     * Provide invalid externally resolved parameter values.
     */
    public static function invalidResolvedParameters(): iterable
    {
        yield [new Unique(new UniqueExternalReference(null)), 'Unique table must resolve to a string.'];
        yield [new Unique('users', new UniqueExternalReference(42)), 'Unique column must resolve to a string or null.'];
        yield [
            new Unique('users', connection: new UniqueExternalReference(false)),
            'Unique connection must resolve to a string or null.',
        ];
        yield [
            new Unique('users', ignoreColumn: new UniqueExternalReference(false)),
            'Unique ignoreColumn must resolve to a string or null.',
        ];
        yield [
            new Unique('users', withoutTrashed: new UniqueExternalReference('true')),
            'Unique withoutTrashed must resolve to a boolean.',
        ];
        yield [
            new Unique('users', deletedAtColumn: new UniqueExternalReference(null)),
            'Unique deletedAtColumn must resolve to a string.',
        ];
    }

    /**
     * Test invalid database constraints fail clearly.
     */
    public function testRejectsInvalidDatabaseConstraint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Each where item must be a DatabaseConstraint or Closure');

        (new Unique('users', where: ['invalid']))->getRule(ValidationPath::create());
    }
}

class UniqueExternalReference implements ExternalReference
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
