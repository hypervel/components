<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Attributes\Validation\In as InAttribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\In as InRule;

class InTest extends TestCase
{
    /**
     * Test an explicitly supplied native rule is preserved.
     */
    public function testReturnsProvidedRule(): void
    {
        $rule = new InRule(['admin', 'editor']);

        $this->assertSame(
            [$rule],
            (new RuleDenormalizer)->execute(new InAttribute($rule), ValidationPath::create()),
        );
    }

    /**
     * Test an externally supplied native rule is preserved.
     */
    public function testReturnsExternallyReferencedRule(): void
    {
        $rule = new InRule(['admin', 'editor']);

        $this->assertSame(
            [$rule],
            (new RuleDenormalizer)->execute(
                new InAttribute(new InExternalReference($rule)),
                ValidationPath::create(),
            ),
        );
    }

    /**
     * Test values resolve, convert, and flatten before native rule construction.
     */
    public function testBuildsRuleFromNestedValues(): void
    {
        $attribute = new InAttribute(
            new InArrayable([
                InRole::Admin,
                ['editor'],
                new InExternalReference(new InArrayable(['viewer', ['owner']])),
            ]),
        );

        $rule = (new RuleDenormalizer)->execute($attribute, ValidationPath::create())[0];

        $this->assertSame('in:"admin","editor","viewer","owner"', (string) $rule);
    }

    /**
     * Test parsed parameters build a native in rule.
     */
    public function testCreatesFromParsedParameters(): void
    {
        $rule = (new RuleDenormalizer)->execute(
            InAttribute::create('admin', 'editor'),
            ValidationPath::create(),
        )[0];

        $this->assertSame('in:"admin","editor"', (string) $rule);
    }
}

class InExternalReference implements ExternalReference
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
class InArrayable implements Arrayable
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

enum InRole: string
{
    case Admin = 'admin';
}
