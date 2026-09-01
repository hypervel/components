<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Attributes\Validation\NotIn as NotInAttribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\NotIn as NotInRule;

class NotInTest extends TestCase
{
    /**
     * Test an explicitly supplied native rule is preserved.
     */
    public function testReturnsProvidedRule(): void
    {
        $rule = new NotInRule(['admin', 'editor']);

        $this->assertSame(
            [$rule],
            (new RuleDenormalizer)->execute(new NotInAttribute($rule), ValidationPath::create()),
        );
    }

    /**
     * Test an externally supplied native rule is preserved.
     */
    public function testReturnsExternallyReferencedRule(): void
    {
        $rule = new NotInRule(['admin', 'editor']);

        $this->assertSame(
            [$rule],
            (new RuleDenormalizer)->execute(
                new NotInAttribute(new NotInExternalReference($rule)),
                ValidationPath::create(),
            ),
        );
    }

    /**
     * Test values resolve, convert, and flatten before native rule construction.
     */
    public function testBuildsRuleFromNestedValues(): void
    {
        $attribute = new NotInAttribute(
            new NotInArrayable([
                NotInRole::Admin,
                ['editor'],
                new NotInExternalReference(new NotInArrayable(['viewer', ['owner']])),
            ]),
        );

        $rule = (new RuleDenormalizer)->execute($attribute, ValidationPath::create())[0];

        $this->assertSame('not_in:"admin","editor","viewer","owner"', (string) $rule);
    }

    /**
     * Test parsed parameters build a native not-in rule.
     */
    public function testCreatesFromParsedParameters(): void
    {
        $rule = (new RuleDenormalizer)->execute(
            NotInAttribute::create('admin', 'editor'),
            ValidationPath::create(),
        )[0];

        $this->assertSame('not_in:"admin","editor"', (string) $rule);
    }
}

class NotInExternalReference implements ExternalReference
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

/**
 * @implements Arrayable<array-key, mixed>
 */
class NotInArrayable implements Arrayable
{
    public function __construct(protected array $values)
    {
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->values;
    }
}

enum NotInRole: string
{
    case Admin = 'admin';
}
