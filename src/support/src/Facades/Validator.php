<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hyperf\Validation\Contract\ValidatorFactoryInterface;

/**
 * @method static array parseData(array $data)
 * @method static \Hyperf\Validation\Validator after(callable|string $callback)
 * @method static bool passes()
 * @method static bool fails()
 * @method static array validate()
 * @method static array validated()
 * @method static void addFailure(string $attribute, string $rule, array $parameters = [])
 * @method static array valid()
 * @method static array invalid()
 * @method static array failed()
 * @method static \Hyperf\Support\MessageBag messages()
 * @method static \Hyperf\Contract\MessageBag errors()
 * @method static \Hyperf\Contract\MessageBag getMessageBag()
 * @method static bool hasRule(string $attribute, array|string|\Stringable $rules)
 * @method static array attributes()
 * @method static array getData()
 * @method static \Hyperf\Validation\Validator setData(array $data)
 * @method static array getRules()
 * @method static \Hyperf\Validation\Validator setRules(array $rules)
 * @method static void addRules(array $rules)
 * @method static \Hyperf\Validation\Validator sometimes(array|string $attribute, array|string $rules, callable $callback)
 * @method static void addExtensions(array $extensions)
 * @method static void addImplicitExtensions(array $extensions)
 * @method static void addDependentExtensions(array $extensions)
 * @method static void addExtension(string $rule, \Closure|string $extension)
 * @method static void addImplicitExtension(string $rule, \Closure|string $extension)
 * @method static void addDependentExtension(string $rule, \Closure|string $extension)
 * @method static void addReplacers(array $replacers)
 * @method static void addReplacer(string $rule, \Closure|string $replacer)
 * @method static \Hyperf\Validation\Validator setCustomMessages(array $messages)
 * @method static \Hyperf\Validation\Validator setAttributeNames(array $attributes)
 * @method static \Hyperf\Validation\Validator addCustomAttributes(array $customAttributes)
 * @method static \Hyperf\Validation\Validator setValueNames(array $values)
 * @method static \Hyperf\Validation\Validator addCustomValues(array $customValues)
 * @method static void setFallbackMessages(array $messages)
 * @method static \Hyperf\Validation\Contract\PresenceVerifierInterface getPresenceVerifier()
 * @method static \Hyperf\Validation\Contract\PresenceVerifierInterface getPresenceVerifierFor(string|null $connection)
 * @method static void setPresenceVerifier(\Hyperf\Validation\Contract\PresenceVerifierInterface $presenceVerifier)
 * @method static \Hyperf\Contract\TranslatorInterface getTranslator()
 * @method static void setTranslator(\Hyperf\Contract\TranslatorInterface $translator)
 * @method static void setContainer(\Psr\Container\ContainerInterface $container)
 * @method static void getValue(string $attribute)
 * @method static void setValue(string $attribute, mixed $value)
 * @method static string makeReplacements(string $message, string $attribute, string $rule, array $parameters)
 * @method static string getDisplayableAttribute(string $attribute)
 * @method static string getDisplayableValue(string $attribute, mixed $value)
 * @method static bool validateAccepted(string $attribute, mixed $value)
 * @method static bool validateAcceptedIf(string $attribute, mixed $value, void $parameters)
 * @method static bool validateDeclined(string $attribute, mixed $value)
 * @method static bool validateDeclinedIf(string $attribute, mixed $value, mixed $parameters)
 * @method static bool validateActiveUrl(string $attribute, mixed $value)
 * @method static bool validateAscii(string $attribute, mixed $value)
 * @method static bool validateBail()
 * @method static bool validateBefore(string $attribute, mixed $value, array $parameters)
 * @method static bool validateBeforeOrEqual(string $attribute, mixed $value, array $parameters)
 * @method static bool validateAfter(string $attribute, mixed $value, array $parameters)
 * @method static bool validateAfterOrEqual(string $attribute, mixed $value, array $parameters)
 * @method static bool validateAlpha(string $attribute, mixed $value, mixed $parameters)
 * @method static bool validateAlphaDash(mixed $attribute, mixed $value, mixed $parameters)
 * @method static bool validateAlphaNum(string $attribute, mixed $value, mixed $parameters)
 * @method static bool validateArray(string $attribute, mixed $value, array $parameters = [])
 * @method static bool validateList(string $attribute, mixed $value)
 * @method static bool validateRequiredArrayKeys(string $attribute, mixed $value, array $parameters)
 * @method static bool validateBetween(string $attribute, mixed $value, array $parameters)
 * @method static bool validateBoolean(string $attribute, mixed $value, array $parameters = [])
 * @method static bool validateConfirmed(string $attribute, mixed $value)
 * @method static bool validateDate(string $attribute, mixed $value)
 * @method static bool validateDateFormat(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDateEquals(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDecimal(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDifferent(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDigits(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDigitsBetween(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDimensions(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDistinct(string $attribute, mixed $value, array $parameters)
 * @method static bool validateEmail(string $attribute, mixed $value)
 * @method static bool validateExists(string $attribute, mixed $value, array $parameters)
 * @method static bool validateUnique(string $attribute, mixed $value, array $parameters)
 * @method static array parseTable(string $table)
 * @method static string getQueryColumn(array $parameters, string $attribute)
 * @method static string guessColumnForQuery(string $attribute)
 * @method static bool validateFile(string $attribute, mixed $value)
 * @method static bool validateFilled(string $attribute, mixed $value)
 * @method static bool validateGt(string $attribute, mixed $value, array $parameters)
 * @method static bool validateLt(string $attribute, mixed $value, array $parameters)
 * @method static bool validateGte(string $attribute, mixed $value, array $parameters)
 * @method static bool validateLte(string $attribute, mixed $value, array $parameters)
 * @method static bool validateLowercase(string $attribute, mixed $value, array $parameters)
 * @method static bool validateUppercase(string $attribute, mixed $value, array $parameters)
 * @method static bool validateImage(string $attribute, mixed $value)
 * @method static bool validateIn(string $attribute, mixed $value, array $parameters)
 * @method static bool validateInArray(string $attribute, mixed $value, array $parameters)
 * @method static bool validateInteger(string $attribute, mixed $value, array $parameters = [])
 * @method static bool validateIp(string $attribute, mixed $value)
 * @method static bool validateIpv4(string $attribute, mixed $value)
 * @method static bool validateIpv6(string $attribute, mixed $value)
 * @method static bool validateMacAddress(string $attribute, mixed $value)
 * @method static bool validateJson(string $attribute, mixed $value)
 * @method static bool validateMax(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMaxDigits(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMimes(string $attribute, \SplFileInfo $value, array $parameters)
 * @method static bool validateMimetypes(string $attribute, \SplFileInfo $value, array $parameters)
 * @method static bool validateMin(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMinDigits(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMissing(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMissingIf(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMissingUnless(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMissingWith(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMissingWithAll(string $attribute, mixed $value, array $parameters)
 * @method static bool validateMultipleOf(string $attribute, mixed $value, array $parameters)
 * @method static array parseDependentRuleParameters(array $parameters)
 * @method static bool validateNullable()
 * @method static bool validateNotIn(string $attribute, mixed $value, array $parameters)
 * @method static bool validateNumeric(string $attribute, mixed $value)
 * @method static bool validatePresent(string $attribute, mixed $value)
 * @method static bool validateRegex(string $attribute, mixed $value, array $parameters)
 * @method static bool validateNotRegex(string $attribute, mixed $value, array $parameters)
 * @method static bool validateRequired(string $attribute, mixed $value)
 * @method static bool validateProhibits(string $attribute, mixed $value, mixed $parameters)
 * @method static bool validateRequiredIf(string $attribute, mixed $value, array $parameters)
 * @method static bool validateExclude()
 * @method static bool validateExcludeIf(string $attribute, mixed $value, array $parameters)
 * @method static bool validateExcludeUnless(string $attribute, mixed $value, array $parameters)
 * @method static bool validateRequiredUnless(string $attribute, mixed $value, array $parameters)
 * @method static bool validateExcludeWith(string $attribute, mixed $value, array $parameters)
 * @method static bool validateExcludeWithout(string $attribute, mixed $value, array $parameters)
 * @method static bool validateRequiredWith(string $attribute, mixed $value, array $parameters)
 * @method static bool validateRequiredWithAll(string $attribute, mixed $value, array $parameters)
 * @method static bool validateRequiredWithout(string $attribute, mixed $value, array $parameters)
 * @method static bool validateRequiredWithoutAll(string $attribute, mixed $value, array $parameters)
 * @method static bool validateSame(string $attribute, mixed $value, array $parameters)
 * @method static bool validateSize(string $attribute, mixed $value, array $parameters)
 * @method static void validateSometimes()
 * @method static bool validateStartsWith(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDoesntStartWith(string $attribute, mixed $value, array $parameters)
 * @method static bool validateEndsWith(string $attribute, mixed $value, array $parameters)
 * @method static bool validateDoesntEndWith(void $attribute, void $value, void $parameters)
 * @method static bool validateString(string $attribute, mixed $value)
 * @method static bool validateTimezone(string $attribute, mixed $value)
 * @method static bool validateUrl(string $attribute, mixed $value, array $parameters = [])
 * @method static bool validateUlid(string $attribute, mixed $value)
 * @method static bool validateUuid(string $attribute, mixed $value)
 * @method static bool isValidFileInstance(mixed $value)
 * @method static void requireParameterCount(int $count, array $parameters, string $rule)
 *
 * @see \Hyperf\Validation\Validator
 */
class Validator extends Facade
{
    protected static function getFacadeAccessor()
    {
        return ValidatorFactoryInterface::class;
    }
}
