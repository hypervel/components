<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use DateTimeZone;
use Egulias\EmailValidator\Validation\RFCValidation;
use Hypervel\Data\Attributes\Validation\Accepted;
use Hypervel\Data\Attributes\Validation\AcceptedIf;
use Hypervel\Data\Attributes\Validation\ActiveUrl;
use Hypervel\Data\Attributes\Validation\After;
use Hypervel\Data\Attributes\Validation\AfterOrEqual;
use Hypervel\Data\Attributes\Validation\Alpha;
use Hypervel\Data\Attributes\Validation\AlphaDash;
use Hypervel\Data\Attributes\Validation\AlphaNumeric;
use Hypervel\Data\Attributes\Validation\AnyOf;
use Hypervel\Data\Attributes\Validation\ArrayType;
use Hypervel\Data\Attributes\Validation\Ascii;
use Hypervel\Data\Attributes\Validation\Bail;
use Hypervel\Data\Attributes\Validation\Base64;
use Hypervel\Data\Attributes\Validation\Before;
use Hypervel\Data\Attributes\Validation\BeforeOrEqual;
use Hypervel\Data\Attributes\Validation\Between;
use Hypervel\Data\Attributes\Validation\BooleanType;
use Hypervel\Data\Attributes\Validation\Can;
use Hypervel\Data\Attributes\Validation\Confirmed;
use Hypervel\Data\Attributes\Validation\Contains;
use Hypervel\Data\Attributes\Validation\CurrentPassword;
use Hypervel\Data\Attributes\Validation\Date;
use Hypervel\Data\Attributes\Validation\DateEquals;
use Hypervel\Data\Attributes\Validation\DateFormat;
use Hypervel\Data\Attributes\Validation\Decimal;
use Hypervel\Data\Attributes\Validation\Declined;
use Hypervel\Data\Attributes\Validation\DeclinedIf;
use Hypervel\Data\Attributes\Validation\Different;
use Hypervel\Data\Attributes\Validation\Digits;
use Hypervel\Data\Attributes\Validation\DigitsBetween;
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
use Hypervel\Data\Attributes\Validation\ExcludeUnless;
use Hypervel\Data\Attributes\Validation\ExcludeWith;
use Hypervel\Data\Attributes\Validation\ExcludeWithout;
use Hypervel\Data\Attributes\Validation\Extensions;
use Hypervel\Data\Attributes\Validation\File;
use Hypervel\Data\Attributes\Validation\Filled;
use Hypervel\Data\Attributes\Validation\GreaterThan;
use Hypervel\Data\Attributes\Validation\GreaterThanOrEqualTo;
use Hypervel\Data\Attributes\Validation\HexColor;
use Hypervel\Data\Attributes\Validation\Image;
use Hypervel\Data\Attributes\Validation\InArray;
use Hypervel\Data\Attributes\Validation\InArrayKeys;
use Hypervel\Data\Attributes\Validation\IntegerType;
use Hypervel\Data\Attributes\Validation\IP;
use Hypervel\Data\Attributes\Validation\IPv4;
use Hypervel\Data\Attributes\Validation\IPv6;
use Hypervel\Data\Attributes\Validation\Json;
use Hypervel\Data\Attributes\Validation\LessThan;
use Hypervel\Data\Attributes\Validation\LessThanOrEqualTo;
use Hypervel\Data\Attributes\Validation\ListType;
use Hypervel\Data\Attributes\Validation\Lowercase;
use Hypervel\Data\Attributes\Validation\MacAddress;
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\MaxDigits;
use Hypervel\Data\Attributes\Validation\Mimes;
use Hypervel\Data\Attributes\Validation\MimeTypes;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\MinDigits;
use Hypervel\Data\Attributes\Validation\Missing;
use Hypervel\Data\Attributes\Validation\MissingIf;
use Hypervel\Data\Attributes\Validation\MissingUnless;
use Hypervel\Data\Attributes\Validation\MissingWith;
use Hypervel\Data\Attributes\Validation\MissingWithAll;
use Hypervel\Data\Attributes\Validation\MultipleOf;
use Hypervel\Data\Attributes\Validation\NotRegex;
use Hypervel\Data\Attributes\Validation\Nullable;
use Hypervel\Data\Attributes\Validation\Numeric;
use Hypervel\Data\Attributes\Validation\Present;
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
use Hypervel\Data\Attributes\Validation\Same;
use Hypervel\Data\Attributes\Validation\Size;
use Hypervel\Data\Attributes\Validation\Sometimes;
use Hypervel\Data\Attributes\Validation\StartsWith;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Attributes\Validation\StringValidationAttribute;
use Hypervel\Data\Attributes\Validation\Timezone;
use Hypervel\Data\Attributes\Validation\Ulid;
use Hypervel\Data\Attributes\Validation\Uppercase;
use Hypervel\Data\Attributes\Validation\Url;
use Hypervel\Data\Attributes\Validation\Uuid;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\AnyOf as AnyOfRule;
use Hypervel\Validation\Rules\Can as CanRule;
use Hypervel\Validation\Rules\Dimensions as DimensionsRule;
use Hypervel\Validation\Rules\Enum as EnumRule;
use Hypervel\Validation\Rules\ExcludeIf as ExcludeIfRule;
use Hypervel\Validation\Rules\ProhibitedIf as ProhibitedIfRule;
use Hypervel\Validation\Rules\RequiredIf as RequiredIfRule;
use Hypervel\Validation\ValidationRuleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;

class ValidationAttributeTest extends TestCase
{
    /**
     * Test rules expose their Validator string representation.
     */
    public function testGetsAStringRepresentationOfRules(): void
    {
        $this->assertSame('string', (string) new StringType);
    }

    /**
     * Test rule parameters normalize to Validator string values.
     */
    #[DataProvider('normalizedValues')]
    public function testNormalizesValues(mixed $input, string $output): void
    {
        $attribute = new class([$input]) extends StringValidationAttribute {
            /**
             * Create a test validation attribute.
             *
             * @param list<mixed> $parameters
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

    /**
     * Test simple attributes compile from objects and parsed string parameters.
     */
    #[DataProvider('stringRules')]
    public function testCompilesStringValidationAttributes(
        StringValidationAttribute $attribute,
        string $expected,
    ): void {
        $rules = (new RuleDenormalizer)->execute(
            $attribute,
            ValidationPath::create(),
        );

        $this->assertSame([$expected], $rules);

        [, $parameters] = ValidationRuleParser::parse($expected);
        $createdAttribute = $attribute::create(...$parameters);

        if ((new ReflectionMethod($attribute, 'create'))->getDeclaringClass()->getName() !== StringValidationAttribute::class) {
            return;
        }

        $this->assertSame(
            [$expected],
            (new RuleDenormalizer)->execute($createdAttribute, ValidationPath::create()),
        );
    }

    /**
     * Provide simple string validation attributes.
     */
    public static function stringRules(): iterable
    {
        yield [new Accepted, 'accepted'];
        yield [new AcceptedIf('status', true), 'accepted_if:status,true'];
        yield [new ActiveUrl, 'active_url'];
        yield [new After('tomorrow'), 'after:tomorrow'];
        yield [new AfterOrEqual('tomorrow'), 'after_or_equal:tomorrow'];
        yield [new Alpha, 'alpha'];
        yield [new AlphaDash, 'alpha_dash'];
        yield [new AlphaNumeric, 'alpha_num'];
        yield [new ArrayType(['name', 'email']), 'array:name,email'];
        yield [new Ascii, 'ascii'];
        yield [new Bail, 'bail'];
        yield [new Base64, 'base64'];
        yield [new Before('tomorrow'), 'before:tomorrow'];
        yield [new BeforeOrEqual('tomorrow'), 'before_or_equal:tomorrow'];
        yield [new Between(1, 10), 'between:1,10'];
        yield [new BooleanType, 'boolean'];
        yield [new Confirmed, 'confirmed'];
        yield [new Contains(['admin', [42]], new ValidationAttributeExternalReference('member')), 'contains:admin,42,member'];
        yield [new CurrentPassword, 'current_password'];
        yield [new CurrentPassword('api'), 'current_password:api'];
        yield [new CurrentPassword(ValidationAttributeBackedEnum::Foo), 'current_password:foo'];
        yield [new CurrentPassword(new ValidationAttributeExternalReference), 'current_password:admin'];
        yield [CurrentPassword::create('api'), 'current_password:api'];
        yield [new Date, 'date'];
        yield [new DateEquals('tomorrow'), 'date_equals:tomorrow'];
        yield [new DateFormat('Y-m-d'), 'date_format:Y-m-d'];
        yield [new DateFormat(['Y-m-d', 'Y-m-d H:i:s']), 'date_format:Y-m-d,Y-m-d H:i:s'];
        yield [new DateFormat('Y-m-d', 'Y-m-d H:i:s'), 'date_format:Y-m-d,Y-m-d H:i:s'];
        yield [new Decimal('2', '4'), 'decimal:2,4'];
        yield [new Declined, 'declined'];
        yield [new DeclinedIf('status', false), 'declined_if:status,false'];
        yield [new Different('password'), 'different:password'];
        yield [new Digits(4), 'digits:4'];
        yield [new DigitsBetween(2, 6), 'digits_between:2,6'];
        yield [new Distinct, 'distinct'];
        yield [new Distinct(Distinct::Strict), 'distinct:strict'];
        yield [new Distinct(Distinct::IgnoreCase), 'distinct:ignore_case'];
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
        yield [new Email, 'email:rfc'];
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
        yield [new ExcludeUnless('status', 'published'), 'exclude_unless:status,published'];
        yield [new ExcludeWith('archived_at'), 'exclude_with:archived_at'];
        yield [new ExcludeWithout('published_at'), 'exclude_without:published_at'];
        yield [new Extensions(['jpg', ['png']], new ValidationAttributeExternalReference('webp')), 'extensions:jpg,png,webp'];
        yield [new File, 'file'];
        yield [new Filled, 'filled'];
        yield [new GreaterThan('other'), 'gt:other'];
        yield [new GreaterThan(10), 'gt:10'];
        yield [new GreaterThan('99999999999999999999'), 'gt:99999999999999999999'];
        yield [new GreaterThanOrEqualTo('other'), 'gte:other'];
        yield [new GreaterThanOrEqualTo('10'), 'gte:10'];
        yield [new HexColor, 'hex_color'];
        yield [new IP, 'ip'];
        yield [new IPv4, 'ipv4'];
        yield [new IPv6, 'ipv6'];
        yield [new Image, 'image'];
        yield [new InArray('roles.*'), 'in_array:roles.*'];
        yield [new InArrayKeys(['name', [42]], new ValidationAttributeExternalReference('email')), 'in_array_keys:name,42,email'];
        yield [new IntegerType, 'integer'];
        yield [new Json, 'json'];
        yield [new LessThan('other'), 'lt:other'];
        yield [new LessThan('10.50'), 'lt:10.50'];
        yield [new LessThanOrEqualTo('other'), 'lte:other'];
        yield [new LessThanOrEqualTo(10), 'lte:10'];
        yield [new ListType, 'list'];
        yield [new Lowercase, 'lowercase'];
        yield [new MacAddress, 'mac_address'];
        yield [new Max('99999999999999999999'), 'max:99999999999999999999'];
        yield [new MaxDigits(10), 'max_digits:10'];
        yield [
            new MimeTypes(['image/jpeg', ['image/png']], new ValidationAttributeExternalReference('image/webp')),
            'mimetypes:image/jpeg,image/png,image/webp',
        ];
        yield [new Mimes(['jpg', ['png']], new ValidationAttributeExternalReference('webp')), 'mimes:jpg,png,webp'];
        yield [new Min(1.5), 'min:1.5'];
        yield [new MinDigits(2), 'min_digits:2'];
        yield [new Missing, 'missing'];
        yield [new MissingIf('status', true, null), 'missing_if:status,true,null'];
        yield [new MissingUnless('status', 1, 2.5), 'missing_unless:status,1,2.5'];
        yield [new MissingWith(['email', ['phone']]), 'missing_with:email,phone'];
        yield [new MissingWithAll(['email', ['phone']]), 'missing_with_all:email,phone'];
        yield [new MultipleOf('0.000000000000000001'), 'multiple_of:0.000000000000000001'];
        yield [new NotRegex('/foo/'), 'not_regex:/foo/'];
        yield [new Nullable, 'nullable'];
        yield [new Numeric, 'numeric'];
        yield [new Present, 'present'];
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
        yield [new Regex('/foo/'), 'regex:/foo/'];
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
        yield [new Same('password'), 'same:password'];
        yield [new Size('99999999999999999999'), 'size:99999999999999999999'];
        yield [new Sometimes, 'sometimes'];
        yield [
            new StartsWith(['admin', ['root']], new ValidationAttributeExternalReference('system')),
            'starts_with:admin,root,system',
        ];
        yield [new Timezone, 'timezone'];
        yield [new Ulid, 'ulid'];
        yield [new Uppercase, 'uppercase'];
        yield [new Url(['http', ['https']], new ValidationAttributeExternalReference('ftp')), 'url:http,https,ftp'];
        yield [new Uuid, 'uuid'];
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
     * Test dimensions attributes compile to native rule objects.
     */
    public function testCompilesDimensionsAttributes(): void
    {
        $rules = (new RuleDenormalizer)->execute(
            new Dimensions(minWidth: 15, maxHeight: 100, ratio: 1.5),
            ValidationPath::create(),
        );

        $this->assertCount(1, $rules);
        $this->assertInstanceOf(DimensionsRule::class, $rules[0]);
        $this->assertSame('dimensions:min_width=15,max_height=100,ratio=1.5', (string) $rules[0]);
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
     * Test parsed dimensions parameters use named constraints.
     */
    public function testCreatesDimensionsAttributeFromParameters(): void
    {
        $rules = (new RuleDenormalizer)->execute(
            Dimensions::create('min_width=15', 'max_height=100', 'ratio=1.5'),
            ValidationPath::create(),
        );

        $this->assertSame('dimensions:min_width=15,max_height=100,ratio=1.5', (string) $rules[0]);
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
     * Test rule attributes flatten wrapped rule declarations.
     */
    public function testCompilesWrappedRuleAttributes(): void
    {
        $rules = (new RuleDenormalizer)->execute(
            new RuleAttribute('string|required', new Min(3), ['nullable']),
            ValidationPath::create(),
        );

        $this->assertSame(['string', 'required', 'min:3', 'nullable'], $rules);
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
     * Provide unsupported email modes.
     */
    public static function invalidEmailModes(): iterable
    {
        yield ['unsupported'];
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
