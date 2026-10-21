<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use DateTimeZone;
use Egulias\EmailValidator\Validation\RFCValidation;
use Hypervel\Data\Attributes\Validation\AnyOf;
use Hypervel\Data\Attributes\Validation\ArrayType;
use Hypervel\Data\Attributes\Validation\Ascii;
use Hypervel\Data\Attributes\Validation\Base64;
use Hypervel\Data\Attributes\Validation\Can;
use Hypervel\Data\Attributes\Validation\Contains;
use Hypervel\Data\Attributes\Validation\CurrentPassword;
use Hypervel\Data\Attributes\Validation\DateFormat;
use Hypervel\Data\Attributes\Validation\Decimal;
use Hypervel\Data\Attributes\Validation\DeclinedIf;
use Hypervel\Data\Attributes\Validation\Dimensions;
use Hypervel\Data\Attributes\Validation\Distinct;
use Hypervel\Data\Attributes\Validation\DoesntContain;
use Hypervel\Data\Attributes\Validation\DoesntEndWith;
use Hypervel\Data\Attributes\Validation\DoesntStartWith;
use Hypervel\Data\Attributes\Validation\Email;
use Hypervel\Data\Attributes\Validation\Encoding;
use Hypervel\Data\Attributes\Validation\EndsWith;
use Hypervel\Data\Attributes\Validation\Enum;
use Hypervel\Data\Attributes\Validation\Exclude;
use Hypervel\Data\Attributes\Validation\ExcludeIf;
use Hypervel\Data\Attributes\Validation\Extensions;
use Hypervel\Data\Attributes\Validation\GreaterThan;
use Hypervel\Data\Attributes\Validation\HexColor;
use Hypervel\Data\Attributes\Validation\InArray;
use Hypervel\Data\Attributes\Validation\InArrayKeys;
use Hypervel\Data\Attributes\Validation\LessThan;
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Mimes;
use Hypervel\Data\Attributes\Validation\MimeTypes;
use Hypervel\Data\Attributes\Validation\Missing;
use Hypervel\Data\Attributes\Validation\MissingIf;
use Hypervel\Data\Attributes\Validation\MissingUnless;
use Hypervel\Data\Attributes\Validation\MissingWith;
use Hypervel\Data\Attributes\Validation\MissingWithAll;
use Hypervel\Data\Attributes\Validation\MultipleOf;
use Hypervel\Data\Attributes\Validation\NotRegex;
use Hypervel\Data\Attributes\Validation\PresentIf;
use Hypervel\Data\Attributes\Validation\PresentUnless;
use Hypervel\Data\Attributes\Validation\PresentWith;
use Hypervel\Data\Attributes\Validation\PresentWithAll;
use Hypervel\Data\Attributes\Validation\Prohibited;
use Hypervel\Data\Attributes\Validation\ProhibitedIf;
use Hypervel\Data\Attributes\Validation\ProhibitedIfAccepted;
use Hypervel\Data\Attributes\Validation\ProhibitedIfDeclined;
use Hypervel\Data\Attributes\Validation\ProhibitedUnless;
use Hypervel\Data\Attributes\Validation\Prohibits;
use Hypervel\Data\Attributes\Validation\Regex;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\RequiredArrayKeys;
use Hypervel\Data\Attributes\Validation\RequiredIf;
use Hypervel\Data\Attributes\Validation\RequiredIfAccepted;
use Hypervel\Data\Attributes\Validation\RequiredIfDeclined;
use Hypervel\Data\Attributes\Validation\RequiredUnless;
use Hypervel\Data\Attributes\Validation\RequiredWith;
use Hypervel\Data\Attributes\Validation\RequiredWithAll;
use Hypervel\Data\Attributes\Validation\RequiredWithout;
use Hypervel\Data\Attributes\Validation\RequiredWithoutAll;
use Hypervel\Data\Attributes\Validation\Rule as RuleAttribute;
use Hypervel\Data\Attributes\Validation\Size;
use Hypervel\Data\Attributes\Validation\StartsWith;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Attributes\Validation\StringValidationAttribute;
use Hypervel\Data\Attributes\Validation\Url;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\RuleNormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Data\Support\Validation\ValidationRuleFactory;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Rules\AnyOf as AnyOfRule;
use Hypervel\Validation\Rules\Can as CanRule;
use Hypervel\Validation\Rules\Dimensions as DimensionsRule;
use Hypervel\Validation\Rules\Enum as EnumRule;
use Hypervel\Validation\Rules\ExcludeIf as ExcludeIfRule;
use Hypervel\Validation\Rules\ProhibitedIf as ProhibitedIfRule;
use Hypervel\Validation\Rules\RequiredIf as RequiredIfRule;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

class ValidationAttributeTest extends TestCase
{
    /**
     * Test rules expose their Validator string representation.
     */
    public function testCanGetAStringRepresentationOfRules(): void
    {
        $this->assertSame('string', (string) new StringType);
    }

    #[DataProvider('normalizedValues')]
    public function testCanNormalizeValues(mixed $input, string $output, ?string $key = null): void
    {
        $attribute = new class($key === null ? [$input] : [$key => $input]) extends StringValidationAttribute {
            /**
             * Create a test validation attribute.
             *
             * @param array<array-key, mixed> $parameters
             */
            public function __construct(protected array $parameters)
            {
            }

            /**
             * Create the attribute from parsed string parameters.
             */
            public static function create(string ...$parameters): static
            {
                return new self($parameters);
            }

            /**
             * Get the Validator rule keyword.
             */
            public static function keyword(): string
            {
                return 'test';
            }

            /**
             * Get the rule parameters.
             */
            public function parameters(): array
            {
                return $this->parameters;
            }
        };

        $this->assertSame("test:{$output}", (string) $attribute);
    }

    /**
     * Provide normalized rule parameter values.
     */
    public static function normalizedValues(): iterable
    {
        yield ['Hello world', 'Hello world'];
        yield [42, '42'];
        yield [3.14, '3.14'];
        yield [true, 'true'];
        yield [false, 'false'];
        yield [['a', 'b', 'c'], 'a,b,c'];
        yield [[null], 'null'];
        yield ['last,first', '"last,first"'];
        yield ['a"b', '"a""b"'];
        yield ['path\\', 'path\\'];
        yield [[['a,b'], 'c'], '"a,b",c'];
        yield [new ValidationAttributeExternalReference(['a,b', 'c']), '"a,b",c'];
        yield ['a,b', '"name=a,b"', 'name'];
        yield [
            CarbonImmutable::create(
                2020,
                5,
                16,
                0,
                0,
                0,
                new DateTimeZone('Europe/Brussels'),
            ),
            '2020-05-16T00:00:00+02:00',
        ];
        yield [ValidationAttributeBackedEnum::Foo, 'foo'];
        yield [
            [ValidationAttributeBackedEnum::Foo, ValidationAttributeBackedEnum::Boo],
            'foo,boo',
        ];
    }

    #[DataProvider('literalParameterRules')]
    public function testValidatesLiteralAttributeParameters(
        StringValidationAttribute $attribute,
        array $data,
        bool $passes,
    ): void {
        $rules = (new RuleDenormalizer)->execute($attribute, ValidationPath::create());
        $validator = new Validator(new Translator(new ArrayLoader, 'en'), $data, ['value' => $rules]);

        $this->assertSame($passes, $validator->passes());
    }

    /**
     * Provide attributes whose literal parameters contain rule delimiters.
     */
    public static function literalParameterRules(): iterable
    {
        yield 'RFC2822 date' => [new DateFormat(DATE_RFC2822), ['value' => 'Tue, 02 Jan 2024 12:00:00 +0000'], true];
        yield 'literal array key' => [new ArrayType('last,first'), ['value' => ['last,first' => 'Taylor']], true];
        yield 'split array key' => [new ArrayType('last,first'), ['value' => ['last' => 'Taylor']], false];
        yield 'matching dependent value' => [new RequiredIf('status', 'a,b'), ['status' => 'a,b'], false];
        yield 'partial dependent value' => [new RequiredIf('status', 'a,b'), ['status' => 'a'], true];
        yield 'raw regex' => [new Regex('/^a,"b"\|c$/'), ['value' => 'a,"b"|c'], true];
        yield 'raw negative regex' => [new NotRegex('/^a,"b"\|c$/'), ['value' => 'a,"b"|c'], false];
    }

    /**
     * Test Hypervel's additional attributes and parameter forms compile, and their rule strings rebuild them losslessly.
     */
    #[DataProvider('stringRules')]
    public function testCompilesStringValidationAttributes(
        StringValidationAttribute $attribute,
        string $expected,
    ): void {
        $denormalizer = new RuleDenormalizer;

        $this->assertSame([$expected], $denormalizer->execute($attribute, ValidationPath::create()));

        $normalized = (new RuleNormalizer(new ValidationRuleFactory))->execute($expected);

        $this->assertCount(1, $normalized);
        $this->assertNotInstanceOf(RuleAttribute::class, $normalized[0]);
        $this->assertSame([$expected], $denormalizer->execute($normalized, ValidationPath::create()));
    }

    /**
     * Provide the attributes and parameter forms RulesTest's upstream dataset does not cover.
     */
    public static function stringRules(): iterable
    {
        yield [new Ascii, 'ascii'];
        yield [new Base64, 'base64'];
        yield [new Contains(['admin', [42]], new ValidationAttributeExternalReference('member')), 'contains:admin,42,member'];
        yield [new CurrentPassword(new ValidationAttributeExternalReference), 'current_password:admin'];
        yield [new Decimal('2', '4'), 'decimal:2,4'];
        yield [new DeclinedIf('status', false), 'declined_if:status,false'];
        yield [new Distinct(new ValidationAttributeExternalReference(Distinct::Strict)), 'distinct:strict'];
        yield [new Distinct(new ValidationAttributeExternalReference(null)), 'distinct'];
        yield [
            new DoesntContain(['admin', [42]], new ValidationAttributeExternalReference('member')),
            'doesnt_contain:admin,42,member',
        ];
        yield [
            new DoesntEndWith(['.php', ['.exe']], new ValidationAttributeExternalReference('.bat')),
            'doesnt_end_with:.php,.exe,.bat',
        ];
        yield [
            new DoesntStartWith(['admin', ['root']], new ValidationAttributeExternalReference('system')),
            'doesnt_start_with:admin,root,system',
        ];
        yield [
            new Email(Email::DnsCheckValidation, Email::FilterUnicodeEmailValidation),
            'email:dns,filter_unicode',
        ];
        yield [new Email(RFCValidation::class), 'email:' . RFCValidation::class];
        yield [new Email(new ValidationAttributeExternalReference(Email::SpoofCheckValidation)), 'email:spoof'];
        yield [new Encoding('UTF-8'), 'encoding:UTF-8'];
        yield [
            new EndsWith(['.json', ['.yaml']], new ValidationAttributeExternalReference('.yml')),
            'ends_with:.json,.yaml,.yml',
        ];
        yield [new ExcludeIf('status', false), 'exclude_if:status,false'];
        yield [new Extensions(['jpg', ['png']], new ValidationAttributeExternalReference('webp')), 'extensions:jpg,png,webp'];
        yield [new GreaterThan('99999999999999999999'), 'gt:99999999999999999999'];
        yield [new HexColor, 'hex_color'];
        yield [new InArray('roles.*'), 'in_array:roles.*'];
        yield [new InArrayKeys(['name', [42]], new ValidationAttributeExternalReference('email')), 'in_array_keys:name,42,email'];
        yield [new LessThan('10.50'), 'lt:10.50'];
        yield [new Max('99999999999999999999'), 'max:99999999999999999999'];
        yield [
            new MimeTypes(['image/jpeg', ['image/png']], new ValidationAttributeExternalReference('image/webp')),
            'mimetypes:image/jpeg,image/png,image/webp',
        ];
        yield [new Mimes(['jpg', ['png']], new ValidationAttributeExternalReference('webp')), 'mimes:jpg,png,webp'];
        yield [new Missing, 'missing'];
        yield [new MissingIf('status', true, null), 'missing_if:status,true,null'];
        yield [new MissingUnless('status', 1, 2.5), 'missing_unless:status,1,2.5'];
        yield [new MissingWith(['email', ['phone']]), 'missing_with:email,phone'];
        yield [new MissingWithAll(['email', ['phone']]), 'missing_with_all:email,phone'];
        yield [new MultipleOf('0.000000000000000001'), 'multiple_of:0.000000000000000001'];
        yield [new PresentIf('status', true, null), 'present_if:status,true,null'];
        yield [new PresentUnless('status', 1, 2.5), 'present_unless:status,1,2.5'];
        yield [new PresentWith(['email', ['phone']]), 'present_with:email,phone'];
        yield [new PresentWithAll(['email', ['phone']]), 'present_with_all:email,phone'];
        yield [
            new ProhibitedIf('status', ['draft', ['pending']], new ValidationAttributeExternalReference('published')),
            'prohibited_if:status,draft,pending,published',
        ];
        yield [new ProhibitedIf('enabled', true), 'prohibited_if:enabled,true'];
        yield [new ProhibitedIfAccepted('terms'), 'prohibited_if_accepted:terms'];
        yield [new ProhibitedIfDeclined('terms'), 'prohibited_if_declined:terms'];
        yield [
            new ProhibitedUnless('status', ['draft', ['pending']], new ValidationAttributeExternalReference('published')),
            'prohibited_unless:status,draft,pending,published',
        ];
        yield [new ProhibitedUnless('count', 1, 2.5), 'prohibited_unless:count,1,2.5'];
        yield [new Prohibits(['email', ['phone']]), 'prohibits:email,phone'];
        yield [
            new RequiredArrayKeys(['name', ['email']], new ValidationAttributeExternalReference('role')),
            'required_array_keys:name,email,role',
        ];
        yield [
            new RequiredIf('status', ['draft', ['pending']], new ValidationAttributeExternalReference('published')),
            'required_if:status,draft,pending,published',
        ];
        yield [new RequiredIf('enabled', true), 'required_if:enabled,true'];
        yield [new RequiredIfAccepted('terms'), 'required_if_accepted:terms'];
        yield [new RequiredIfDeclined('terms'), 'required_if_declined:terms'];
        yield [
            new RequiredIf('status', 'draft', new ValidationAttributeExternalReference(null)),
            'required_if:status,draft,null',
        ];
        yield [new RequiredUnless('status', null), 'required_unless:status,null'];
        yield [new RequiredWith(['email', ['phone']]), 'required_with:email,phone'];
        yield [new RequiredWithAll(['email', ['phone']]), 'required_with_all:email,phone'];
        yield [new RequiredWithout(['email', ['phone']]), 'required_without:email,phone'];
        yield [new RequiredWithoutAll(['email', ['phone']]), 'required_without_all:email,phone'];
        yield [new Size('99999999999999999999'), 'size:99999999999999999999'];
        yield [
            new StartsWith(['admin', ['root']], new ValidationAttributeExternalReference('system')),
            'starts_with:admin,root,system',
        ];
        yield [new Url(['http', ['https']], new ValidationAttributeExternalReference('ftp')), 'url:http,https,ftp'];
    }

    /**
     * Test any-of attributes compile to configured native rules.
     */
    public function testCompilesAnyOfAttributes(): void
    {
        $rules = [['string'], ['integer']];
        $rule = (new AnyOf($rules))->getRule(ValidationPath::create());

        $this->assertInstanceOf(AnyOfRule::class, $rule);
        $this->assertSame($rules, (new ReflectionProperty($rule, 'rules'))->getValue($rule));
    }

    /**
     * Test any-of rules cannot be built from string parameters.
     */
    public function testCannotCreateAnyOfFromStringParameters(): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIs('Cannot create an any-of rule from string parameters.');

        AnyOf::create();
    }

    /**
     * Test can attributes compile to configured native rules.
     */
    public function testCompilesCanAttributes(): void
    {
        $rule = (new Can('update', 'post', 42))->getRule(ValidationPath::create());

        $this->assertInstanceOf(CanRule::class, $rule);
        $this->assertSame('update', (new ReflectionProperty($rule, 'ability'))->getValue($rule));
        $this->assertSame(['post', 42], (new ReflectionProperty($rule, 'arguments'))->getValue($rule));
    }

    /**
     * Test can rules cannot be built from string parameters.
     */
    public function testCannotCreateCanFromStringParameters(): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIs('Cannot create a can rule from string parameters.');

        Can::create();
    }

    /**
     * Test dimensions attributes retain an explicitly supplied rule.
     */
    public function testUsesProvidedDimensionsRule(): void
    {
        $rule = (new DimensionsRule)->width(320);
        $rules = (new RuleDenormalizer)->execute(
            new Dimensions(rule: $rule),
            ValidationPath::create(),
        );

        $this->assertSame([$rule], $rules);
    }

    /**
     * Test enum attributes compile to configured native rule objects.
     */
    public function testCompilesEnumAttributes(): void
    {
        $rules = (new RuleDenormalizer)->execute(
            new Enum(ValidationAttributeBackedEnum::class, only: [ValidationAttributeBackedEnum::Foo]),
            ValidationPath::create(),
        );

        $this->assertCount(1, $rules);
        $this->assertInstanceOf(EnumRule::class, $rules[0]);
        $this->assertSame('in:"foo"', (string) $rules[0]);

        $rule = new EnumRule(ValidationAttributeBackedEnum::class);

        $this->assertSame(
            [$rule],
            (new RuleDenormalizer)->execute(
                new Enum(new ValidationAttributeExternalReference($rule)),
                ValidationPath::create(),
            ),
        );

        $createdRule = (new RuleDenormalizer)->execute(
            Enum::create(ValidationAttributeBackedEnum::class),
            ValidationPath::create(),
        )[0];

        $this->assertSame('in:"foo","boo"', (string) $createdRule);
    }

    /**
     * Test exclude attributes compile to strings or supplied native rules.
     */
    public function testCompilesExcludeAttributes(): void
    {
        $denormalizer = new RuleDenormalizer;
        $path = ValidationPath::create();

        $this->assertSame(['exclude'], $denormalizer->execute(new Exclude, $path));

        $rule = new ExcludeIfRule(true);

        $this->assertSame([$rule], $denormalizer->execute(new Exclude($rule), $path));
        $this->assertSame(['exclude'], $denormalizer->execute(Exclude::create(), $path));
    }

    /**
     * Test prohibited attributes compile to strings or supplied native rules.
     */
    public function testCompilesProhibitedAttributes(): void
    {
        $denormalizer = new RuleDenormalizer;
        $path = ValidationPath::create();

        $this->assertSame(['prohibited'], $denormalizer->execute(new Prohibited, $path));

        $rule = new ProhibitedIfRule(true);

        $this->assertSame([$rule], $denormalizer->execute(new Prohibited($rule), $path));
        $this->assertSame(['prohibited'], $denormalizer->execute(Prohibited::create(), $path));
    }

    /**
     * Test required attributes compile to strings or supplied native rules.
     */
    public function testCompilesRequiredAttributes(): void
    {
        $denormalizer = new RuleDenormalizer;
        $path = ValidationPath::create();

        $this->assertSame(['required'], $denormalizer->execute(new Required, $path));

        $rule = new RequiredIfRule(true);

        $this->assertSame([$rule], $denormalizer->execute(new Required($rule), $path));
        $this->assertSame(['required'], $denormalizer->execute(Required::create(), $path));
    }

    /**
     * Test empty dimensions declarations fail clearly.
     */
    public function testRejectsEmptyDimensionsAttribute(): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIs('You must specify one of width, height, minWidth, minHeight, maxWidth, maxHeight, ratio or a dimensions rule.');

        new Dimensions;
    }

    /**
     * Test distinct rejects unsupported resolved modes.
     */
    public function testRejectsInvalidDistinctMode(): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessageIs('Distinct mode should be ignore_case or strict.');

        (new Distinct(new ValidationAttributeExternalReference('invalid')))->parameters();
    }

    /**
     * Test email rejects unsupported resolved modes.
     */
    #[DataProvider('invalidEmailModes')]
    public function testRejectsInvalidEmailMode(string|ExternalReference $mode): void
    {
        $this->expectException(CannotBuildValidationRule::class);

        (new Email($mode))->parameters();
    }

    /**
     * Provide unsupported email modes; RulesTest covers a literal unsupported mode.
     */
    public static function invalidEmailModes(): iterable
    {
        yield [new ValidationAttributeExternalReference(['rfc', 'dns'])];
    }

    /**
     * Test enum rejects unsupported resolved declarations.
     */
    public function testRejectsInvalidEnumDeclaration(): void
    {
        $this->expectException(CannotBuildValidationRule::class);

        (new Enum(new ValidationAttributeExternalReference(42)))->getRule(ValidationPath::create());
    }
}

class ValidationAttributeExternalReference implements ExternalReference
{
    public function __construct(protected mixed $value = 'admin')
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

enum ValidationAttributeBackedEnum: string
{
    case Foo = 'foo';
    case Boo = 'boo';
}
