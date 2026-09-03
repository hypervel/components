<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Email extends StringValidationAttribute
{
    public const string RfcValidation = 'rfc';

    public const string NoRfcWarningsValidation = 'strict';

    public const string DnsCheckValidation = 'dns';

    public const string SpoofCheckValidation = 'spoof';

    public const string FilterEmailValidation = 'filter';

    public const string FilterUnicodeEmailValidation = 'filter_unicode';

    protected array $modes;

    /**
     * Create a new email validation attribute.
     */
    public function __construct(array|string|ExternalReference ...$modes)
    {
        $this->modes = Arr::flatten($modes);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'email';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        $modes = $this->modes === [] ? [self::RfcValidation] : $this->modes;
        $parameters = [];

        foreach ($modes as $mode) {
            $mode = $this->normalizePossibleExternalReferenceParameter($mode);

            if (! is_string($mode) || (! in_array($mode, [
                self::RfcValidation,
                self::NoRfcWarningsValidation,
                self::DnsCheckValidation,
                self::SpoofCheckValidation,
                self::FilterEmailValidation,
                self::FilterUnicodeEmailValidation,
            ], true) && ! class_exists($mode))) {
                throw CannotBuildValidationRule::create(sprintf(
                    'Email validation mode [%s] is not supported.',
                    is_string($mode) ? $mode : get_debug_type($mode),
                ));
            }

            $parameters[] = $mode;
        }

        return $parameters;
    }
}
