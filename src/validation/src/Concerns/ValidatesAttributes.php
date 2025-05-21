<?php

declare(strict_types=1);

namespace Hypervel\Validation\Concerns;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException as BrickMathException;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\DNSCheckValidation;
use Egulias\EmailValidator\Validation\Extra\SpoofCheckValidation;
use Egulias\EmailValidator\Validation\MultipleValidationWithAnd;
use Egulias\EmailValidator\Validation\NoRFCWarningsValidation;
use Egulias\EmailValidator\Validation\RFCValidation;
use Exception;
use Hyperf\Database\Model\Model;
use Hyperf\HttpMessage\Upload\UploadedFile;
use Hypervel\Context\ApplicationContext;
use Hypervel\Support\Arr;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Exceptions\MathException;
use Hypervel\Support\Str;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;
use Hypervel\Validation\ValidationData;
use InvalidArgumentException;
use SplFileInfo;
use ValueError;

trait ValidatesAttributes
{
    /**
     * Validate that an attribute was "accepted".
     *
     * This validation rule implies the attribute is "required".
     */
    public function validateAccepted(string $attribute, mixed $value): bool
    {
        $acceptable = ['yes', 'on', '1', 1, true, 'true'];

        return $this->validateRequired($attribute, $value) && in_array($value, $acceptable, true);
    }

    /**
     * Validate that an attribute was "accepted" when another attribute has a given value.
     */
    public function validateAcceptedIf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $acceptable = ['yes', 'on', '1', 1, true, 'true'];

        $this->requireParameterCount(2, $parameters, 'accepted_if');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validateRequired($attribute, $value) && in_array($value, $acceptable, true);
        }

        return true;
    }

    /**
     * Validate that an attribute was "declined".
     *
     * This validation rule implies the attribute is "required".
     */
    public function validateDeclined(string $attribute, mixed $value): bool
    {
        $acceptable = ['no', 'off', '0', 0, false, 'false'];

        return $this->validateRequired($attribute, $value) && in_array($value, $acceptable, true);
    }

    /**
     * Validate that an attribute was "declined" when another attribute has a given value.
     */
    public function validateDeclinedIf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $acceptable = ['no', 'off', '0', 0, false, 'false'];

        $this->requireParameterCount(2, $parameters, 'declined_if');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validateRequired($attribute, $value) && in_array($value, $acceptable, true);
        }

        return true;
    }

    /**
     * Validate that an attribute is an active URL.
     */
    public function validateActiveUrl(string $attribute, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if ($url = parse_url($value, PHP_URL_HOST)) {
            try {
                $records = $this->getDnsRecords($url . '.', DNS_A | DNS_AAAA);

                if (is_array($records) && count($records) > 0) {
                    return true;
                }
            } catch (Exception) {
                return false;
            }
        }

        return false;
    }

    /**
     * Get the DNS records for the given hostname.
     */
    protected function getDnsRecords(string $hostname, int $type): array|false
    {
        return dns_get_record($hostname, $type);
    }

    /**
     * Validate that an attribute is 7 bit ASCII.
     */
    public function validateAscii(string $attribute, mixed $value): bool
    {
        return Str::isAscii((string) $value);
    }

    /**
     * "Break" on first validation fail.
     *
     * Always returns true, just lets us put "bail" in rules.
     */
    public function validateBail(): bool
    {
        return true;
    }

    /**
     * Validate the date is before a given date.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateBefore(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'before');

        return $this->compareDates($attribute, $value, $parameters, '<');
    }

    /**
     * Validate the date is before or equal a given date.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateBeforeOrEqual(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'before_or_equal');

        return $this->compareDates($attribute, $value, $parameters, '<=');
    }

    /**
     * Validate the date is after a given date.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateAfter(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'after');

        return $this->compareDates($attribute, $value, $parameters, '>');
    }

    /**
     * Validate the date is equal or after a given date.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateAfterOrEqual(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'after_or_equal');

        return $this->compareDates($attribute, $value, $parameters, '>=');
    }

    /**
     * Compare a given date against another using an operator.
     *
     * @param array<int, int|string> $parameters
     */
    protected function compareDates(string $attribute, mixed $value, array $parameters, string $operator): bool
    {
        if (! is_string($value) && ! is_numeric($value) && ! $value instanceof DateTimeInterface) {
            return false;
        }

        if ($format = $this->getDateFormat($attribute)) {
            return $this->checkDateTimeOrder($format, $value, $parameters[0], $operator);
        }

        if (is_null($date = $this->getDateTimestamp($parameters[0]))) {
            $date = $this->getDateTimestamp($this->getValue($parameters[0]));
        }

        return $this->compare($this->getDateTimestamp($value), $date, $operator);
    }

    /**
     * Get the date format for an attribute if it has one.
     */
    protected function getDateFormat(string $attribute): ?string
    {
        if ($result = $this->getRule($attribute, 'DateFormat')) {
            return $result[1][0];
        }

        return null;
    }

    /**
     * Get the date timestamp.
     */
    protected function getDateTimestamp(mixed $value): ?int
    {
        $date = is_null($value) ? null : $this->getDateTime($value);

        return $date ? $date->getTimestamp() : null;
    }

    /**
     * Given two date/time strings, check that one is after the other.
     */
    protected function checkDateTimeOrder(string $format, string $first, string $second, string $operator): bool
    {
        $firstDate = $this->getDateTimeWithOptionalFormat($format, $first);

        $format = $this->getDateFormat($second) ?: $format;

        if (! $secondDate = $this->getDateTimeWithOptionalFormat($format, $second)) {
            if (is_null($second = $this->getValue($second))) {
                return true;
            }

            $secondDate = $this->getDateTimeWithOptionalFormat($format, $second);
        }

        return ($firstDate && $secondDate) && $this->compare($firstDate, $secondDate, $operator);
    }

    /**
     * Get a DateTime instance from a string.
     */
    protected function getDateTimeWithOptionalFormat(string $format, string $value): ?DateTime
    {
        if ($date = DateTime::createFromFormat('!' . $format, $value)) {
            return $date;
        }

        return $this->getDateTime($value);
    }

    /**
     * Get a DateTime instance from a string with no format.
     */
    protected function getDateTime(DateTimeInterface|string $value): ?DateTime
    {
        try {
            return @Carbon::parse($value) ?: null;
        } catch (Exception) {
        }

        return null;
    }

    /**
     * Validate that an attribute contains only alphabetic characters.
     * If the 'ascii' option is passed, validate that an attribute contains only ascii alphabetic characters.
     */
    public function validateAlpha(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (isset($parameters[0]) && $parameters[0] === 'ascii') {
            return is_string($value) && preg_match('/\A[a-zA-Z]+\z/u', $value);
        }

        return is_string($value) && preg_match('/\A[\pL\pM]+\z/u', $value);
    }

    /**
     * Validate that an attribute contains only alpha-numeric characters, dashes, and underscores.
     * If the 'ascii' option is passed, validate that an attribute contains only ascii alpha-numeric characters,
     * dashes, and underscores.
     */
    public function validateAlphaDash(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        if (isset($parameters[0]) && $parameters[0] === 'ascii') {
            return preg_match('/\A[a-zA-Z0-9_-]+\z/u', (string) $value) > 0;
        }

        return preg_match('/\A[\pL\pM\pN_-]+\z/u', (string) $value) > 0;
    }

    /**
     * Validate that an attribute contains only alpha-numeric characters.
     * If the 'ascii' option is passed, validate that an attribute contains only ascii alpha-numeric characters.
     */
    public function validateAlphaNum(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        if (isset($parameters[0]) && $parameters[0] === 'ascii') {
            return preg_match('/\A[a-zA-Z0-9]+\z/u', (string) $value) > 0;
        }

        return preg_match('/\A[\pL\pM\pN]+\z/u', (string) $value) > 0;
    }

    /**
     * Validate that an attribute is an array.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateArray(string $attribute, mixed $value, array $parameters = []): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if (empty($parameters)) {
            return true;
        }

        return empty(array_diff_key($value, array_fill_keys($parameters, '')));
    }

    /**
     * Validate that an attribute is a list.
     */
    public function validateList(string $attribute, mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Validate that an array has all of the given keys.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateRequiredArrayKeys(string $attribute, mixed $value, array $parameters): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($parameters as $param) {
            if (! Arr::exists($value, $param)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the size of an attribute is between a set of values.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateBetween(string $attribute, mixed $value, array $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'between');

        return with(
            BigNumber::of($this->getSize($attribute, $value)),
            fn ($size) => $size->isGreaterThanOrEqualTo($this->trim($parameters[0])) && $size->isLessThanOrEqualTo($this->trim($parameters[1]))
        );
    }

    /**
     * Validate that an attribute is a boolean.
     */
    public function validateBoolean(string $attribute, mixed $value): bool
    {
        $acceptable = [true, false, 0, 1, '0', '1'];

        return in_array($value, $acceptable, true);
    }

    /**
     * Validate that an attribute has a matching confirmation.
     *
     * @param array{0: string} $parameters
     */
    public function validateConfirmed(string $attribute, mixed $value, mixed $parameters): bool
    {
        return $this->validateSame($attribute, $value, [$parameters[0] ?? $attribute . '_confirmation']);
    }

    /**
     * Validate an attribute contains a list of values.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateContains(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($parameters as $parameter) {
            if (! in_array($parameter, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that the password of the currently authenticated user matches the given value.
     *
     * @param array<int, int|string> $parameters
     */
    protected function validateCurrentPassword(string $attribute, mixed $value, mixed $parameters): bool
    {
        $auth = $this->container->get(\Hypervel\Auth\Contracts\Factory::class);
        $hasher = $this->container->get(\Hypervel\Hashing\Contracts\Hasher::class);

        $guard = $auth->guard(Arr::first($parameters));

        if ($guard->guest()) {
            return false;
        }

        return $hasher->check($value, $guard->user()->getAuthPassword());
    }

    /**
     * Validate that an attribute is a valid date.
     */
    public function validateDate(string $attribute, mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        try {
            if ((! is_string($value) && ! is_numeric($value)) || strtotime($value) === false) {
                return false;
            }
        } catch (Exception) {
            return false;
        }

        $date = date_parse($value);

        return checkdate((int) $date['month'], (int) $date['day'], (int) $date['year']);
    }

    /**
     * Validate that an attribute matches a date format.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDateFormat(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'date_format');

        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        foreach ($parameters as $format) {
            try {
                $date = DateTime::createFromFormat('!' . $format, $value);

                if ($date && $date->format($format) == $value) {
                    return true;
                }
            } catch (ValueError) {
                return false;
            }
        }

        return false;
    }

    /**
     * Validate that an attribute is equal to another date.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDateEquals(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'date_equals');

        return $this->compareDates($attribute, $value, $parameters, '=');
    }

    /**
     * Validate that an attribute has a given number of decimal places.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDecimal(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'decimal');

        if (! $this->validateNumeric($attribute, $value)) {
            return false;
        }

        $matches = [];

        if (preg_match('/^[+-]?\d*\.?(\d*)$/', (string) $value, $matches) !== 1) {
            return false;
        }

        $decimals = strlen(end($matches));

        if (! isset($parameters[1])) {
            return $decimals == $parameters[0];
        }

        return $decimals >= $parameters[0]
            && $decimals <= $parameters[1];
    }

    /**
     * Validate that an attribute is different from another attribute.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDifferent(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'different');

        foreach ($parameters as $parameter) {
            if (Arr::has($this->data, $parameter)) {
                $other = Arr::get($this->data, $parameter);

                if ($value === $other) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate that an attribute has a given number of digits.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDigits(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'digits');

        $value = (string) $value;

        return ! preg_match('/[^0-9]/', $value)
            && strlen($value) == $parameters[0];
    }

    /**
     * Validate that an attribute is between a given number of digits.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDigitsBetween(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'digits_between');

        $length = strlen($value = (string) $value);

        return ! preg_match('/[^0-9]/', $value)
            && $length >= $parameters[0] && $length <= $parameters[1];
    }

    /**
     * Validate the dimensions of an image matches the given values.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDimensions(string $attribute, mixed $value, mixed $parameters): bool
    {
        if ($this->isValidFileInstance($value) && in_array($value->getMimeType(), ['image/svg+xml', 'image/svg'])) {
            return true;
        }

        if (! $this->isValidFileInstance($value)) {
            return false;
        }

        $dimensions = method_exists($value, 'dimensions')
            ? $value->dimensions()
            : @getimagesize($value->getRealPath());

        if (! $dimensions) {
            return false;
        }

        $this->requireParameterCount(1, $parameters, 'dimensions');

        [$width, $height] = $dimensions;

        $parameters = $this->parseNamedParameters($parameters);

        return ! ($this->failsBasicDimensionChecks($parameters, $width, $height)
            || $this->failsRatioCheck($parameters, $width, $height)
            || $this->failsMinRatioCheck($parameters, $width, $height)
            || $this->failsMaxRatioCheck($parameters, $width, $height)
        );
    }

    /**
     * Test if the given width and height fail any conditions.
     *
     * @param array<string,string> $parameters
     */
    protected function failsBasicDimensionChecks(array $parameters, int $width, int $height): bool
    {
        return (isset($parameters['width']) && $parameters['width'] != $width)
            || (isset($parameters['min_width']) && $parameters['min_width'] > $width)
            || (isset($parameters['max_width']) && $parameters['max_width'] < $width)
            || (isset($parameters['height']) && $parameters['height'] != $height)
            || (isset($parameters['min_height']) && $parameters['min_height'] > $height)
            || (isset($parameters['max_height']) && $parameters['max_height'] < $height);
    }

    /**
     * Determine if the given parameters fail a dimension ratio check.
     */
    protected function failsRatioCheck(array $parameters, int $width, int $height): bool
    {
        if (! isset($parameters['ratio'])) {
            return false;
        }

        [$numerator, $denominator] = array_replace(
            [1, 1],
            array_filter(sscanf($parameters['ratio'], '%f/%d'))
        );

        $precision = 1 / (max(($width + $height) / 2, $height) + 1);

        return abs($numerator / $denominator - $width / $height) > $precision;
    }

    /**
     * Determine if the given parameters fail a dimension minimum ratio check.
     */
    private function failsMinRatioCheck(array $parameters, int $width, int $height): bool
    {
        if (! isset($parameters['min_ratio'])) {
            return false;
        }

        [$minNumerator, $minDenominator] = array_replace(
            [1, 1],
            array_filter(sscanf($parameters['min_ratio'], '%f/%d'))
        );

        return ($width / $height) > ($minNumerator / $minDenominator);
    }

    /**
     * Determine if the given parameters fail a dimension maximum ratio check.
     */
    private function failsMaxRatioCheck(array $parameters, int $width, int $height): bool
    {
        if (! isset($parameters['max_ratio'])) {
            return false;
        }

        [$maxNumerator, $maxDenominator] = array_replace(
            [1, 1],
            array_filter(sscanf($parameters['max_ratio'], '%f/%d'))
        );

        return ($width / $height) < ($maxNumerator / $maxDenominator);
    }

    /**
     * Validate an attribute is unique among other values.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDistinct(string $attribute, mixed $value, mixed $parameters): bool
    {
        $data = Arr::except($this->getDistinctValues($attribute), $attribute);

        if (in_array('ignore_case', $parameters)) {
            return empty(preg_grep('/^' . preg_quote((string) $value, '/') . '$/iu', $data));
        }

        return ! in_array($value, array_values($data), in_array('strict', $parameters));
    }

    /**
     * Get the values to distinct between.
     */
    protected function getDistinctValues(string $attribute): array
    {
        $attributeName = $this->getPrimaryAttribute($attribute);

        if (! property_exists($this, 'distinctValues')) {
            return $this->extractDistinctValues($attributeName);
        }

        if (! array_key_exists($attributeName, $this->distinctValues)) {
            $this->distinctValues[$attributeName] = $this->extractDistinctValues($attributeName);
        }

        return $this->distinctValues[$attributeName];
    }

    /**
     * Extract the distinct values from the data.
     */
    protected function extractDistinctValues(string $attribute): array
    {
        $attributeData = ValidationData::extractDataFromPath(
            ValidationData::getLeadingExplicitAttributePath($attribute),
            $this->data
        );

        $pattern = str_replace('\*', '[^.]+', preg_quote($attribute, '#'));

        return Arr::where(Arr::dot($attributeData), function ($value, $key) use ($pattern) {
            return (bool) preg_match('#^' . $pattern . '\z#u', (string) $key);
        });
    }

    /**
     * Validate that an attribute is a valid e-mail address.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateEmail(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! is_string($value) && ! (is_object($value) && method_exists($value, '__toString'))) {
            return false;
        }

        $validations = (new Collection($parameters))
            ->unique()
            ->map(fn ($validation) => match (true) {
                $validation === 'strict' => new NoRFCWarningsValidation(),
                $validation === 'dns' => new DNSCheckValidation(),
                $validation === 'spoof' => new SpoofCheckValidation(),
                $validation === 'filter' => new FilterEmailValidation(),
                $validation === 'filter_unicode' => FilterEmailValidation::unicode(),
                is_string($validation) && class_exists($validation) => $this->container->make($validation),
                default => new RFCValidation(),
            })
            ->values()
            ->all() ?: [new RFCValidation()];

        $emailValidator = ApplicationContext::getContainer()->get(EmailValidator::class);

        return $emailValidator->isValid((string) $value, new MultipleValidationWithAnd($validations));
    }

    /**
     * Validate the existence of an attribute value in a database table.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateExists(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'exists');

        [$connection, $table] = $this->parseTable($parameters[0]);

        // The second parameter position holds the name of the column that should be
        // verified as existing. If this parameter is not specified we will guess
        // that the columns being "verified" shares the given attribute's name.
        $column = $this->getQueryColumn($parameters, $attribute);

        $expected = is_array($value) ? count(array_unique($value)) : 1;

        if ($expected === 0) {
            return true;
        }

        return $this->getExistCount(
            $connection,
            $table,
            $column,
            $value,
            $parameters
        ) >= $expected;
    }

    /**
     * Get the number of records that exist in storage.
     *
     * @param array<int, int|string> $parameters
     */
    protected function getExistCount(mixed $connection, string $table, string $column, mixed $value, array $parameters): int
    {
        $verifier = $this->getPresenceVerifier($connection);

        $extra = $this->getExtraConditions(
            array_values(array_slice($parameters, 2))
        );

        if ($this->currentRule instanceof Exists) {
            $extra = array_merge($extra, $this->currentRule->queryCallbacks());
        }

        return is_array($value)
            ? $verifier->getMultiCount($table, $column, $value, $extra)
            : $verifier->getCount($table, $column, $value, null, null, $extra);
    }

    /**
     * Validate the uniqueness of an attribute value on a given database table.
     *
     * If a database column is not specified, the attribute will be used.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateUnique(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'unique');

        [$connection, $table, $idColumn] = $this->parseTable($parameters[0]);

        // The second parameter position holds the name of the column that needs to
        // be verified as unique. If this parameter isn't specified we will just
        // assume that this column to be verified shares the attribute's name.
        $column = $this->getQueryColumn($parameters, $attribute);

        $id = null;

        if (isset($parameters[2])) {
            [$idColumn, $id] = $this->getUniqueIds($idColumn, $parameters);

            if (! is_null($id)) {
                $id = stripslashes((string) $id);
            }
        }

        // The presence verifier is responsible for counting rows within this store
        // mechanism which might be a relational database or any other permanent
        // data store like Redis, etc. We will use it to determine uniqueness.
        $verifier = $this->getPresenceVerifier($connection);

        $extra = $this->getUniqueExtra($parameters);

        if ($this->currentRule instanceof Unique) {
            $extra = array_merge($extra, $this->currentRule->queryCallbacks());
        }

        return $verifier->getCount(
            $table,
            $column,
            $value,
            $id,
            $idColumn,
            $extra
        ) == 0;
    }

    /**
     * Get the excluded ID column and value for the unique rule.
     *
     * @param array<int, int|string> $parameters
     */
    protected function getUniqueIds(?string $idColumn, array $parameters): array
    {
        $idColumn ??= $parameters[3] ?? 'id';

        return [$idColumn, $this->prepareUniqueId($parameters[2])];
    }

    /**
     * Prepare the given ID for querying.
     */
    protected function prepareUniqueId(mixed $id): ?int
    {
        if (preg_match('/\[(.*)\]/', (string) $id, $matches)) {
            $id = $this->getValue($matches[1]);
        }

        if (strtolower((string) $id) === 'null') {
            $id = null;
        }

        if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
            $id = (int) $id;
        }

        return $id;
    }

    /**
     * Get the extra conditions for a unique rule.
     *
     * @param array<int, int|string> $parameters
     */
    protected function getUniqueExtra(array $parameters): array
    {
        if (isset($parameters[4])) {
            return $this->getExtraConditions(array_slice($parameters, 4));
        }

        return [];
    }

    /**
     * Parse the connection / table for the unique / exists rules.
     */
    public function parseTable(string $table): array
    {
        [$connection, $table] = str_contains($table, '.') ? explode('.', $table, 2) : [null, $table];

        if (str_contains($table, '\\') && class_exists($table) && is_a($table, Model::class, true)) {
            $model = new $table();

            $table = $model->getTable();
            $connection ??= $model->getConnectionName();

            if (str_contains($table, '.') && Str::startsWith($table, $connection)) {
                $connection = null;
            }

            $idColumn = $model->getKeyName();
        }

        return [$connection, $table, $idColumn ?? null];
    }

    /**
     * Get the column name for an exists / unique query.
     */
    public function getQueryColumn(array $parameters, string $attribute): bool|string
    {
        return isset($parameters[1]) && $parameters[1] !== 'NULL'
            ? $parameters[1]
            : $this->guessColumnForQuery($attribute);
    }

    /**
     * Guess the database column from the given attribute name.
     */
    public function guessColumnForQuery(string $attribute): string
    {
        if (in_array($attribute, Arr::collapse($this->implicitAttributes))
            && ! is_numeric($last = last(explode('.', $attribute)))
        ) {
            return $last;
        }

        return $attribute;
    }

    /**
     * Get the extra conditions for a unique / exists rule.
     */
    protected function getExtraConditions(array $segments): array
    {
        $extra = [];

        $count = count($segments);

        for ($i = 0; $i < $count; $i += 2) {
            $extra[$segments[$i]] = $segments[$i + 1];
        }

        return $extra;
    }

    /**
     * Validate the extension of a file upload attribute is in a set of defined extensions.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateExtensions(string $attribute, mixed $value, array $parameters): bool
    {
        if (! $this->isValidFileInstance($value)) {
            return false;
        }

        if ($this->shouldBlockPhpUpload($value, $parameters)) {
            return false;
        }

        return in_array(strtolower($value->getExtension()), $parameters);
    }

    /**
     * Validate the given value is a valid file.
     */
    public function validateFile(string $attribute, mixed $value): bool
    {
        return $this->isValidFileInstance($value);
    }

    /**
     * Validate the given attribute is filled if it is present.
     */
    public function validateFilled(string $attribute, mixed $value): bool
    {
        if (Arr::has($this->data, $attribute)) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute is greater than another attribute.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateGt(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'gt');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Gt');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return BigNumber::of($this->getSize($attribute, $value))->isGreaterThan($this->trim($parameters[0]));
        }

        if (is_numeric($parameters[0])) {
            return false;
        }

        if ($this->hasRule($attribute, $this->numericRules) && is_numeric($value) && is_numeric($comparedToValue)) {
            return BigNumber::of($this->trim($value))->isGreaterThan($this->trim($comparedToValue));
        }

        if (! $this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize($attribute, $value) > $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Validate that an attribute is less than another attribute.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateLt(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'lt');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Lt');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return BigNumber::of($this->getSize($attribute, $value))->isLessThan($this->trim($parameters[0]));
        }

        if (is_numeric($parameters[0])) {
            return false;
        }

        if ($this->hasRule($attribute, $this->numericRules) && is_numeric($value) && is_numeric($comparedToValue)) {
            return BigNumber::of($this->trim($value))->isLessThan($this->trim($comparedToValue));
        }

        if (! $this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize($attribute, $value) < $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Validate that an attribute is greater than or equal another attribute.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateGte(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'gte');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Gte');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return BigNumber::of($this->getSize($attribute, $value))->isGreaterThanOrEqualTo($this->trim($parameters[0]));
        }

        if (is_numeric($parameters[0])) {
            return false;
        }

        if ($this->hasRule($attribute, $this->numericRules) && is_numeric($value) && is_numeric($comparedToValue)) {
            return BigNumber::of($this->trim($value))->isGreaterThanOrEqualTo($this->trim($comparedToValue));
        }

        if (! $this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize($attribute, $value) >= $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Validate that an attribute is less than or equal another attribute.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateLte(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'lte');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Lte');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return BigNumber::of($this->getSize($attribute, $value))->isLessThanOrEqualTo($this->trim($parameters[0]));
        }

        if (is_numeric($parameters[0])) {
            return false;
        }

        if ($this->hasRule($attribute, $this->numericRules) && is_numeric($value) && is_numeric($comparedToValue)) {
            return BigNumber::of($this->trim($value))->isLessThanOrEqualTo($this->trim($comparedToValue));
        }

        if (! $this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize($attribute, $value) <= $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Validate that an attribute is lowercase.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateLowercase(string $attribute, mixed $value, mixed $parameters): bool
    {
        $value = (string) $value;

        return Str::lower($value) === $value;
    }

    /**
     * Validate that an attribute is uppercase.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateUppercase(string $attribute, mixed $value, mixed $parameters): bool
    {
        $value = (string) $value;

        return Str::upper($value) === $value;
    }

    /**
     * Validate that an attribute is a valid HEX color.
     */
    public function validateHexColor(string $attribute, mixed $value): bool
    {
        return preg_match('/^#(?:(?:[0-9a-f]{3}){1,2}|(?:[0-9a-f]{4}){1,2})$/i', (string) $value) === 1;
    }

    /**
     * Validate the MIME type of a file is an image MIME type.
     */
    public function validateImage(string $attribute, mixed $value, array $parameters = []): bool
    {
        $mimes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

        if (is_array($parameters) && in_array('allow_svg', $parameters)) {
            $mimes[] = 'svg';
        }

        return $this->validateMimes($attribute, $value, $mimes);
    }

    /**
     * Validate an attribute is contained within a list of values.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateIn(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (is_array($value) && $this->hasRule($attribute, 'Array')) {
            foreach ($value as $element) {
                if (is_array($element)) {
                    return false;
                }
            }

            return count(array_diff($value, $parameters)) === 0;
        }

        return ! is_array($value) && in_array((string) $value, $parameters);
    }

    /**
     * Validate that the values of an attribute are in another attribute.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateInArray(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'in_array');

        $explicitPath = ValidationData::getLeadingExplicitAttributePath($parameters[0]);

        $attributeData = ValidationData::extractDataFromPath($explicitPath, $this->data);

        $otherValues = Arr::where(Arr::dot($attributeData), function ($value, $key) use ($parameters) {
            return Str::is($parameters[0], $key);
        });

        return in_array($value, $otherValues);
    }

    /**
     * Validate that an attribute is an integer.
     */
    public function validateInteger(string $attribute, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate that an attribute is a valid IP.
     */
    public function validateIp(string $attribute, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate that an attribute is a valid IPv4.
     */
    public function validateIpv4(string $attribute, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Validate that an attribute is a valid IPv6.
     */
    public function validateIpv6(string $attribute, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Validate that an attribute is a valid MAC address.
     */
    public function validateMacAddress(string $attribute, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_MAC) !== false;
    }

    /**
     * Validate the attribute is a valid JSON string.
     */
    public function validateJson(string $attribute, mixed $value): bool
    {
        if (is_array($value) || is_null($value)) {
            return false;
        }

        if (! is_scalar($value) && ! method_exists($value, '__toString')) {
            return false;
        }

        $value = (string) $value;
        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Validate the size of an attribute is less than or equal to a maximum value.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMax(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'max');

        if ($value instanceof UploadedFile && ! $value->isValid()) {
            return false;
        }

        return BigNumber::of($this->getSize($attribute, $value))->isLessThanOrEqualTo($this->trim($parameters[0]));
    }

    /**
     * Validate that an attribute has a maximum number of digits.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMaxDigits(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'max_digits');

        $length = strlen($value = (string) $value);

        return ! preg_match('/[^0-9]/', $value) && $length <= $parameters[0];
    }

    /**
     * Validate the guessed extension of a file upload is in a set of file extensions.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMimes(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! $this->isValidFileInstance($value)) {
            return false;
        }

        if ($this->shouldBlockPhpUpload($value, $parameters)) {
            return false;
        }

        if (in_array('jpg', $parameters) || in_array('jpeg', $parameters)) {
            $parameters = array_unique(array_merge($parameters, ['jpg', 'jpeg']));
        }

        return $value->getPath() !== '' && in_array($value->getExtension(), $parameters);
    }

    /**
     * Validate the MIME type of a file upload attribute is in a set of MIME types.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMimetypes(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! $this->isValidFileInstance($value)) {
            return false;
        }

        if ($this->shouldBlockPhpUpload($value, $parameters)) {
            return false;
        }

        return $value->getPath() !== ''
            && (in_array($value->getMimeType(), $parameters)
                || in_array(explode('/', $value->getMimeType())[0] . '/*', $parameters));
    }

    /**
     * Check if PHP uploads are explicitly allowed.
     *
     * @param array<int, int|string> $parameters
     */
    protected function shouldBlockPhpUpload(mixed $value, array $parameters): bool
    {
        if (in_array('php', $parameters)) {
            return false;
        }

        $phpExtensions = [
            'php',
            'php3',
            'php4',
            'php5',
            'php7',
            'php8',
            'phtml',
            'phar',
        ];

        return in_array(trim(strtolower($value->getExtension())), $phpExtensions);
    }

    /**
     * Validate the size of an attribute is greater than or equal to a minimum value.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMin(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'min');

        return BigNumber::of($this->getSize($attribute, $value))->isGreaterThanOrEqualTo($this->trim($parameters[0]));
    }

    /**
     * Validate that an attribute has a minimum number of digits.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMinDigits(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'min_digits');

        $length = strlen($value = (string) $value);

        return ! preg_match('/[^0-9]/', $value) && $length >= $parameters[0];
    }

    /**
     * Validate that an attribute is missing.
     *
     * @param array<int, int|string> $parameters
     * @param mixed $attribute
     * @param mixed $value
     */
    public function validateMissing($attribute, $value, $parameters): bool
    {
        return ! Arr::has($this->data, $attribute);
    }

    /**
     * Validate that an attribute is missing when another attribute has a given value.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMissingIf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'missing_if');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validateMissing($attribute, $value, $parameters);
        }

        return true;
    }

    /**
     * Validate that an attribute is missing unless another attribute has a given value.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMissingUnless(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'missing_unless');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (! in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validateMissing($attribute, $value, $parameters);
        }

        return true;
    }

    /**
     * Validate that an attribute is missing when any given attribute is present.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMissingWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'missing_with');

        if (Arr::hasAny($this->data, $parameters)) {
            return $this->validateMissing($attribute, $value, $parameters);
        }

        return true;
    }

    /**
     * Validate that an attribute is missing when all given attributes are present.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMissingWithAll(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'missing_with_all');

        if (Arr::has($this->data, $parameters)) {
            return $this->validateMissing($attribute, $value, $parameters);
        }

        return true;
    }

    /**
     * Validate the value of an attribute is a multiple of a given value.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateMultipleOf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'multiple_of');

        if (! $this->validateNumeric($attribute, $value) || ! $this->validateNumeric($attribute, $parameters[0])) {
            return false;
        }

        try {
            $numerator = BigDecimal::of($this->trim($value));
            $denominator = BigDecimal::of($this->trim($parameters[0]));

            if ($numerator->isZero() && $denominator->isZero()) {
                return false;
            }

            if ($numerator->isZero()) {
                return true;
            }

            if ($denominator->isZero()) {
                return false;
            }

            return $numerator->remainder($denominator)->isZero();
        } catch (BrickMathException $e) {
            throw new MathException('An error occurred while handling the multiple_of input values.', previous: $e);
        }
    }

    /**
     * "Indicate" validation should pass if value is null.
     *
     * Always returns true, just lets us put "nullable" in rules.
     */
    public function validateNullable(): bool
    {
        return true;
    }

    /**
     * Validate an attribute is not contained within a list of values.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateNotIn(string $attribute, mixed $value, mixed $parameters): bool
    {
        return ! $this->validateIn($attribute, $value, $parameters);
    }

    /**
     * Validate that an attribute is numeric.
     */
    public function validateNumeric(string $attribute, mixed $value): bool
    {
        return is_numeric($value);
    }

    /**
     * Validate that an attribute exists even if not filled.
     */
    public function validatePresent(string $attribute, mixed $value): bool
    {
        return Arr::has($this->data, $attribute);
    }

    /**
     * Validate that an attribute is present when another attribute has a given value.
     *
     * @param array<int, int|string> $parameters
     */
    public function validatePresentIf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'present_if');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validatePresent($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute is present unless another attribute has a given value.
     *
     * @param array<int, int|string> $parameters
     */
    public function validatePresentUnless(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'present_unless');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (! in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validatePresent($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute is present when any given attribute is present.
     *
     * @param array<int, int|string> $parameters
     */
    public function validatePresentWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'present_with');

        if (Arr::hasAny($this->data, $parameters)) {
            return $this->validatePresent($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute is present when all given attributes are present.
     *
     * @param array<int, int|string> $parameters
     */
    public function validatePresentWithAll(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'present_with_all');

        if (Arr::has($this->data, $parameters)) {
            return $this->validatePresent($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute passes a regular expression check.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateRegex(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        $this->requireParameterCount(1, $parameters, 'regex');

        return preg_match($parameters[0], (string) $value) > 0;
    }

    /**
     * Validate that an attribute does not pass a regular expression check.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateNotRegex(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        $this->requireParameterCount(1, $parameters, 'not_regex');

        return preg_match($parameters[0], (string) $value) < 1;
    }

    /**
     * Validate that a required attribute exists.
     */
    public function validateRequired(string $attribute, mixed $value): bool
    {
        if (is_null($value)) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_countable($value) && count($value) < 1) {
            return false;
        }
        if ($value instanceof SplFileInfo) {
            return (string) $value->getPath() !== '';
        }

        return true;
    }

    /**
     * Validate that an attribute exists when another attribute has a given value.
     */
    public function validateRequiredIf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'required_if');

        if (! Arr::has($this->data, $parameters[0])) {
            return true;
        }

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute exists when another attribute was "accepted".
     */
    public function validateRequiredIfAccepted(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'required_if_accepted');

        if ($this->validateAccepted($parameters[0], $this->getValue($parameters[0]))) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute exists when another attribute was "declined".
     */
    public function validateRequiredIfDeclined(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'required_if_declined');

        if ($this->validateDeclined($parameters[0], $this->getValue($parameters[0]))) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute does not exist or is an empty string.
     */
    public function validateProhibited(string $attribute, mixed $value): bool
    {
        return ! $this->validateRequired($attribute, $value);
    }

    /**
     * Validate that an attribute does not exist when another attribute has a given value.
     */
    public function validateProhibitedIf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'prohibited_if');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (in_array($other, $values, is_bool($other) || is_null($other))) {
            return ! $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute does not exist when another attribute was "accepted".
     */
    public function validateProhibitedIfAccepted(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'prohibited_if_accepted');

        if ($this->validateAccepted($parameters[0], $this->getValue($parameters[0]))) {
            return $this->validateProhibited($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute does not exist when another attribute was "declined".
     */
    public function validateProhibitedIfDeclined(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'prohibited_if_declined');

        if ($this->validateDeclined($parameters[0], $this->getValue($parameters[0]))) {
            return $this->validateProhibited($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute does not exist unless another attribute has a given value.
     */
    public function validateProhibitedUnless(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'prohibited_unless');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (! in_array($other, $values, is_bool($other) || is_null($other))) {
            return ! $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that other attributes do not exist when this attribute exists.
     */
    public function validateProhibits(string $attribute, mixed $value, mixed $parameters): bool
    {
        if ($this->validateRequired($attribute, $value)) {
            foreach ($parameters as $parameter) {
                if ($this->validateRequired($parameter, Arr::get($this->data, $parameter))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Indicate that an attribute is excluded.
     */
    public function validateExclude(): bool
    {
        return false;
    }

    /**
     * Indicate that an attribute should be excluded when another attribute has a given value.
     */
    public function validateExcludeIf(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'exclude_if');

        if (! Arr::has($this->data, $parameters[0])) {
            return true;
        }

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        return ! in_array($other, $values, is_bool($other) || is_null($other));
    }

    /**
     * Indicate that an attribute should be excluded when another attribute does not have a given value.
     */
    public function validateExcludeUnless(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'exclude_unless');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        return in_array($other, $values, is_bool($other) || is_null($other));
    }

    /**
     * Validate that an attribute exists when another attribute does not have a given value.
     */
    public function validateRequiredUnless(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(2, $parameters, 'required_unless');

        [$values, $other] = $this->parseDependentRuleParameters($parameters);

        if (! in_array($other, $values, is_bool($other) || is_null($other))) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Indicate that an attribute should be excluded when another attribute presents.
     */
    public function validateExcludeWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'exclude_with');

        if (! Arr::has($this->data, $parameters[0])) {
            return true;
        }

        return false;
    }

    /**
     * Indicate that an attribute should be excluded when another attribute is missing.
     */
    public function validateExcludeWithout(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'exclude_without');

        if ($this->anyFailingRequired($parameters)) {
            return false;
        }

        return true;
    }

    /**
     * Prepare the values and the other value for validation.
     *
     * @param array<int, int|string> $parameters
     */
    public function parseDependentRuleParameters(array $parameters): array
    {
        $other = Arr::get($this->data, $parameters[0]);

        $values = array_slice($parameters, 1);

        if ($this->shouldConvertToBoolean($parameters[0]) || is_bool($other)) {
            $values = $this->convertValuesToBoolean($values);
        }

        if (is_null($other)) {
            $values = $this->convertValuesToNull($values);
        }

        return [$values, $other];
    }

    /**
     * Check if parameter should be converted to boolean.
     */
    protected function shouldConvertToBoolean(string $parameter): bool
    {
        return in_array('boolean', $this->rules[$parameter] ?? []);
    }

    /**
     * Convert the given values to boolean if they are string "true" / "false".
     */
    protected function convertValuesToBoolean(array $values): array
    {
        return array_map(function ($value) {
            if ($value === 'true') {
                return true;
            }
            if ($value === 'false') {
                return false;
            }

            return $value;
        }, $values);
    }

    /**
     * Convert the given values to null if they are string "null".
     */
    protected function convertValuesToNull(array $values): array
    {
        return array_map(function ($value) {
            return Str::lower((string) $value) === 'null' ? null : $value;
        }, $values);
    }

    /**
     * Validate that an attribute exists when any other attribute exists.
     */
    public function validateRequiredWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! $this->allFailingRequired($parameters)) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute exists when all other attributes exist.
     */
    public function validateRequiredWithAll(string $attribute, mixed $value, mixed $parameters): bool
    {
        if (! $this->anyFailingRequired($parameters)) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute exists when another attribute does not.
     */
    public function validateRequiredWithout(string $attribute, mixed $value, mixed $parameters): bool
    {
        if ($this->anyFailingRequired($parameters)) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Validate that an attribute exists when all other attributes do not.
     */
    public function validateRequiredWithoutAll(string $attribute, mixed $value, mixed $parameters): bool
    {
        if ($this->allFailingRequired($parameters)) {
            return $this->validateRequired($attribute, $value);
        }

        return true;
    }

    /**
     * Determine if any of the given attributes fail the required test.
     */
    protected function anyFailingRequired(array $attributes): bool
    {
        foreach ($attributes as $key) {
            if (! $this->validateRequired($key, $this->getValue($key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if all of the given attributes fail the required test.
     */
    protected function allFailingRequired(array $attributes): bool
    {
        foreach ($attributes as $key) {
            if ($this->validateRequired($key, $this->getValue($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that two attributes match.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateSame(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'same');

        $other = Arr::get($this->data, $parameters[0]);

        return $value === $other;
    }

    /**
     * Validate the size of an attribute.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateSize(string $attribute, mixed $value, mixed $parameters): bool
    {
        $this->requireParameterCount(1, $parameters, 'size');

        return BigNumber::of($this->getSize($attribute, $value))->isEqualTo($this->trim($parameters[0]));
    }

    /**
     * "Validate" optional attributes.
     *
     * Always returns true, just lets us put sometimes in rules.
     */
    public function validateSometimes(): bool
    {
        return true;
    }

    /**
     * Validate the attribute starts with a given substring.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateStartsWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        return Str::startsWith((string) $value, $parameters);
    }

    /**
     * Validate the attribute does not start with a given substring.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDoesntStartWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        return ! Str::startsWith((string) $value, $parameters);
    }

    /**
     * Validate the attribute ends with a given substring.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateEndsWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        return Str::endsWith((string) $value, $parameters);
    }

    /**
     * Validate the attribute does not end with a given substring.
     *
     * @param array<int, int|string> $parameters
     */
    public function validateDoesntEndWith(string $attribute, mixed $value, mixed $parameters): bool
    {
        return ! Str::endsWith((string) $value, $parameters);
    }

    /**
     * Validate that an attribute is a string.
     */
    public function validateString(string $attribute, mixed $value): bool
    {
        return is_string($value);
    }

    /**
     * Validate that an attribute is a valid timezone.
     */
    public function validateTimezone(string $attribute, mixed $value, array $parameters = []): bool
    {
        return in_array($value, timezone_identifiers_list(
            constant(DateTimeZone::class . '::' . Str::upper($parameters[0] ?? 'ALL')),
            isset($parameters[1]) ? Str::upper($parameters[1]) : null,
        ), true);
    }

    /**
     * Validate that an attribute is a valid URL.
     */
    public function validateUrl(string $attribute, mixed $value, array $parameters = []): bool
    {
        return Str::isUrl($value, $parameters);
    }

    /**
     * Validate that an attribute is a valid ULID.
     */
    public function validateUlid(string $attribute, mixed $value): bool
    {
        return Str::isUlid($value);
    }

    /**
     * Validate that an attribute is a valid UUID.
     *
     * @param array<int, 'max'|int<0, 8>> $parameters
     */
    public function validateUuid(string $attribute, mixed $value, mixed $parameters): bool
    {
        $version = null;

        if ($parameters !== null && count($parameters) === 1) {
            $version = $parameters[0];

            if ($version !== 'max') {
                $version = (int) $parameters[0];
            }
        }

        return Str::isUuid($value, $version);
    }

    /**
     * Get the size of an attribute.
     */
    protected function getSize(string $attribute, mixed $value): float|int|string
    {
        $hasNumeric = $this->hasRule($attribute, $this->numericRules);

        // This method will determine if the attribute is a number, string, or file and
        // return the proper size accordingly. If it is a number, then number itself
        // is the size. If it is a file, we take kilobytes, and for a string the
        // entire length of the string will be considered the attribute size.
        if (is_numeric($value) && $hasNumeric) {
            return $this->ensureExponentWithinAllowedRange($attribute, $this->trim($value));
        }
        if (is_array($value)) {
            return count($value);
        }
        if ($value instanceof SplFileInfo) {
            return $value->getSize() / 1024;
        }

        return mb_strlen((string) $value ?? '');
    }

    /**
     * Check that the given value is a valid file instance.
     */
    public function isValidFileInstance(mixed $value): bool
    {
        if ($value instanceof UploadedFile && ! $value->isValid()) {
            return false;
        }

        return $value instanceof SplFileInfo;
    }

    /**
     * Determine if a comparison passes between the given values.
     *
     * @throws InvalidArgumentException
     */
    protected function compare(mixed $first, mixed $second, string $operator): bool
    {
        return match ($operator) {
            '<' => $first < $second,
            '>' => $first > $second,
            '<=' => $first <= $second,
            '>=' => $first >= $second,
            '=' => $first == $second,
            default => throw new InvalidArgumentException(),
        };
    }

    /**
     * Parse named parameters to $key => $value items.
     *
     * @param array<int, int|string> $parameters
     */
    public function parseNamedParameters(array $parameters): ?array
    {
        return array_reduce($parameters, function ($result, $item) {
            [$key, $value] = array_pad(explode('=', $item, 2), 2, null);

            $result[$key] = $value;

            return $result;
        });
    }

    /**
     * Require a certain number of parameters to be present.
     *
     * @throws InvalidArgumentException
     */
    public function requireParameterCount(int $count, array $parameters, string $rule): void
    {
        if (count($parameters) < $count) {
            throw new InvalidArgumentException("Validation rule {$rule} requires at least {$count} parameters.");
        }
    }

    /**
     * Check if the parameters are of the same type.
     */
    protected function isSameType(mixed $first, mixed $second): bool
    {
        return gettype($first) == gettype($second);
    }

    /**
     * Adds the existing rule to the numericRules array if the attribute's value is numeric.
     */
    protected function shouldBeNumeric(string $attribute, string $rule): void
    {
        if (is_numeric($this->getValue($attribute))) {
            $this->numericRules[] = $rule;
        }
    }

    /**
     * Trim the value if it is a string.
     */
    protected function trim(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Ensure the exponent is within the allowed range.
     */
    protected function ensureExponentWithinAllowedRange(string $attribute, mixed $value): mixed
    {
        $stringValue = (string) $value;

        if (! is_numeric($value) || ! Str::contains($stringValue, 'e', ignoreCase: true)) {
            return $value;
        }

        $scale = (int) (Str::contains($stringValue, 'e')
            ? Str::after($stringValue, 'e')
            : Str::after($stringValue, 'E'));

        $withinRange = (
            $this->ensureExponentWithinAllowedRangeUsing ?? fn ($scale) => $scale <= 1000 && $scale >= -1000
        )($scale, $attribute, $value);

        if (! $withinRange) {
            throw new MathException('Scientific notation exponent outside of allowed range.');
        }

        return $value;
    }
}
