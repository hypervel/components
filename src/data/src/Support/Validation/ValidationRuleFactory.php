<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

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
use Hypervel\Data\Attributes\Validation\Exists;
use Hypervel\Data\Attributes\Validation\Extensions;
use Hypervel\Data\Attributes\Validation\File;
use Hypervel\Data\Attributes\Validation\Filled;
use Hypervel\Data\Attributes\Validation\GreaterThan;
use Hypervel\Data\Attributes\Validation\GreaterThanOrEqualTo;
use Hypervel\Data\Attributes\Validation\HexColor;
use Hypervel\Data\Attributes\Validation\Image;
use Hypervel\Data\Attributes\Validation\In;
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
use Hypervel\Data\Attributes\Validation\NotIn;
use Hypervel\Data\Attributes\Validation\NotRegex;
use Hypervel\Data\Attributes\Validation\Nullable;
use Hypervel\Data\Attributes\Validation\Numeric;
use Hypervel\Data\Attributes\Validation\Password;
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
use Hypervel\Data\Attributes\Validation\Same;
use Hypervel\Data\Attributes\Validation\Size;
use Hypervel\Data\Attributes\Validation\Sometimes;
use Hypervel\Data\Attributes\Validation\StartsWith;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\Attributes\Validation\Timezone;
use Hypervel\Data\Attributes\Validation\Ulid;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Attributes\Validation\Uppercase;
use Hypervel\Data\Attributes\Validation\Url;
use Hypervel\Data\Attributes\Validation\Uuid;
use Hypervel\Data\Attributes\Validation\ValidationAttribute;
use Hypervel\Data\Exceptions\CouldNotCreateValidationRule;
use Hypervel\Support\Str;
use Hypervel\Validation\ValidationRuleParser;

class ValidationRuleFactory
{
    /**
     * The attribute class for each rule keyword.
     *
     * A constant, so the mapping is not rebuilt for every rule string.
     *
     * @var array<string, class-string<ValidationAttribute>>
     */
    protected const array MAPPING = [
        'accepted' => Accepted::class,
        'accepted_if' => AcceptedIf::class,
        'active_url' => ActiveUrl::class,
        'after' => After::class,
        'after_or_equal' => AfterOrEqual::class,
        'alpha' => Alpha::class,
        'alpha_dash' => AlphaDash::class,
        'alpha_num' => AlphaNumeric::class,
        'any_of' => AnyOf::class,
        'array' => ArrayType::class,
        'ascii' => Ascii::class,
        'bail' => Bail::class,
        'base64' => Base64::class,
        'before' => Before::class,
        'before_or_equal' => BeforeOrEqual::class,
        'between' => Between::class,
        'boolean' => BooleanType::class,
        'can' => Can::class,
        'confirmed' => Confirmed::class,
        'contains' => Contains::class,
        'current_password' => CurrentPassword::class,
        'date' => Date::class,
        'date_equals' => DateEquals::class,
        'date_format' => DateFormat::class,
        'decimal' => Decimal::class,
        'declined' => Declined::class,
        'declined_if' => DeclinedIf::class,
        'different' => Different::class,
        'digits' => Digits::class,
        'digits_between' => DigitsBetween::class,
        'dimensions' => Dimensions::class,
        'distinct' => Distinct::class,
        'doesnt_contain' => DoesntContain::class,
        'doesnt_end_with' => DoesntEndWith::class,
        'doesnt_start_with' => DoesntStartWith::class,
        'email' => Email::class,
        'encoding' => Encoding::class,
        'ends_with' => EndsWith::class,
        'enum' => Enum::class,
        'exclude' => Exclude::class,
        'exclude_if' => ExcludeIf::class,
        'exclude_unless' => ExcludeUnless::class,
        'exclude_with' => ExcludeWith::class,
        'exclude_without' => ExcludeWithout::class,
        'exists' => Exists::class,
        'extensions' => Extensions::class,
        'file' => File::class,
        'filled' => Filled::class,
        'gt' => GreaterThan::class,
        'gte' => GreaterThanOrEqualTo::class,
        'hex_color' => HexColor::class,
        'image' => Image::class,
        'in' => In::class,
        'in_array' => InArray::class,
        'in_array_keys' => InArrayKeys::class,
        'integer' => IntegerType::class,
        'ip' => IP::class,
        'ipv4' => IPv4::class,
        'ipv6' => IPv6::class,
        'json' => Json::class,
        'lt' => LessThan::class,
        'lte' => LessThanOrEqualTo::class,
        'list' => ListType::class,
        'lowercase' => Lowercase::class,
        'mac_address' => MacAddress::class,
        'max' => Max::class,
        'max_digits' => MaxDigits::class,
        'mimes' => Mimes::class,
        'mimetypes' => MimeTypes::class,
        'min' => Min::class,
        'min_digits' => MinDigits::class,
        'missing' => Missing::class,
        'missing_if' => MissingIf::class,
        'missing_unless' => MissingUnless::class,
        'missing_with' => MissingWith::class,
        'missing_with_all' => MissingWithAll::class,
        'multiple_of' => MultipleOf::class,
        'not_in' => NotIn::class,
        'not_regex' => NotRegex::class,
        'nullable' => Nullable::class,
        'numeric' => Numeric::class,
        'password' => Password::class,
        'present' => Present::class,
        'present_if' => PresentIf::class,
        'present_unless' => PresentUnless::class,
        'present_with' => PresentWith::class,
        'present_with_all' => PresentWithAll::class,
        'prohibited' => Prohibited::class,
        'prohibited_if' => ProhibitedIf::class,
        'prohibited_if_accepted' => ProhibitedIfAccepted::class,
        'prohibited_if_declined' => ProhibitedIfDeclined::class,
        'prohibited_unless' => ProhibitedUnless::class,
        'prohibits' => Prohibits::class,
        'regex' => Regex::class,
        'required' => Required::class,
        'required_array_keys' => RequiredArrayKeys::class,
        'required_if' => RequiredIf::class,
        'required_if_accepted' => RequiredIfAccepted::class,
        'required_if_declined' => RequiredIfDeclined::class,
        'required_unless' => RequiredUnless::class,
        'required_with' => RequiredWith::class,
        'required_with_all' => RequiredWithAll::class,
        'required_without' => RequiredWithout::class,
        'required_without_all' => RequiredWithoutAll::class,
        'same' => Same::class,
        'size' => Size::class,
        'sometimes' => Sometimes::class,
        'starts_with' => StartsWith::class,
        'string' => StringType::class,
        'timezone' => Timezone::class,
        'ulid' => Ulid::class,
        'unique' => Unique::class,
        'uppercase' => Uppercase::class,
        'url' => Url::class,
        'uuid' => Uuid::class,
    ];

    /**
     * Create the validation attribute for a Validator rule string.
     *
     * @throws CouldNotCreateValidationRule when no attribute has the rule's keyword
     */
    public function create(string $rule): ValidationRule
    {
        [$keyword, $parameters] = ValidationRuleParser::parse($rule);

        $ruleClass = $this->mapping()[Str::snake($keyword)] ?? null;

        if ($ruleClass === null) {
            throw CouldNotCreateValidationRule::create($rule);
        }

        return $ruleClass::create(...$parameters);
    }

    /**
     * Get the attribute class for each rule keyword.
     *
     * @return array<string, class-string<ValidationAttribute>>
     */
    protected function mapping(): array
    {
        return self::MAPPING;
    }
}
