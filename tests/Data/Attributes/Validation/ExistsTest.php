<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use Hypervel\Data\Attributes\Validation\Exists;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\Constraints\WhereConstraint;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Exists as ExistsRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ExistsTest extends TestCase
{
    /**
     * Test an exists attribute requires a table or native rule.
     */
    public function testRequiresTableOrRule(): void
    {
        $this->expectException(CannotBuildValidationRule::class);

        new Exists;
    }

    /**
     * Test an explicitly supplied native rule is preserved.
     */
    public function testReturnsProvidedRule(): void
    {
        $rule = new ExistsRule('users', 'id');

        $this->assertSame(
            [$rule],
            (new RuleDenormalizer)->execute(new Exists(rule: $rule), ValidationPath::create()),
        );
    }

    /**
     * Test externally resolved parameters and constraints configure the native rule.
     */
    public function testBuildsConfiguredRule(): void
    {
        $attribute = new Exists(
            table: new ExistsExternalReference('users'),
            column: new ExistsExternalReference('id'),
            connection: new ExistsExternalReference('tenant'),
            withoutTrashed: new ExistsExternalReference(true),
            deletedAtColumn: new ExistsExternalReference('removed_at'),
            where: new WhereConstraint('status', 'active'),
        );

        $rule = (new RuleDenormalizer)->execute($attribute, ValidationPath::create())[0];

        $this->assertSame('exists:tenant.users,id,removed_at,"NULL",status,"active"', (string) $rule);
    }

    /**
     * Test parsed parameters build a native exists rule.
     */
    public function testCreatesFromParsedParameters(): void
    {
        $rule = (new RuleDenormalizer)->execute(
            Exists::create('users', 'email'),
            ValidationPath::create(),
        )[0];

        $this->assertSame('exists:users,email', (string) $rule);
    }

    /**
     * Test an explicit null column uses the native default sentinel.
     */
    public function testUsesDefaultColumnForExplicitNull(): void
    {
        $rule = (new Exists('users', null))->getRule(ValidationPath::create());

        $this->assertSame('exists:users,NULL', (string) $rule);
    }

    /**
     * Test invalid externally resolved parameters fail clearly.
     */
    #[DataProvider('invalidResolvedParameters')]
    public function testRejectsInvalidResolvedParameter(Exists $attribute, string $message): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessage($message);

        $attribute->getRule(ValidationPath::create());
    }

    /**
     * Provide invalid externally resolved parameter values.
     */
    public static function invalidResolvedParameters(): iterable
    {
        yield [new Exists(new ExistsExternalReference(null)), 'Exists table must resolve to a string.'];
        yield [new Exists('users', new ExistsExternalReference(42)), 'Exists column must resolve to a string or null.'];
        yield [
            new Exists('users', connection: new ExistsExternalReference(false)),
            'Exists connection must resolve to a string or null.',
        ];
        yield [
            new Exists('users', withoutTrashed: new ExistsExternalReference('true')),
            'Exists withoutTrashed must resolve to a boolean.',
        ];
        yield [
            new Exists('users', deletedAtColumn: new ExistsExternalReference(null)),
            'Exists deletedAtColumn must resolve to a string.',
        ];
    }

    /**
     * Test invalid database constraints fail clearly.
     */
    public function testRejectsInvalidDatabaseConstraint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each where item must be a DatabaseConstraint or Closure');

        (new Exists('users', where: ['invalid']))->getRule(ValidationPath::create());
    }
}

class ExistsExternalReference implements ExternalReference
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
