<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Countable;
use DateTimeInterface;
use Hypervel\Support\Traits\Macroable;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\MarkdownConverter;
use Ramsey\Uuid\Codec\TimestampFirstCombCodec;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Generator\CombGenerator;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Uid\Ulid;
use Throwable;
use Traversable;
use voku\helper\ASCII;

class Str
{
    use Macroable;

    /**
     * The list of characters that are considered "invisible" in strings.
     */
    public const INVISIBLE_CHARACTERS = '\x{0009}\x{0020}\x{00A0}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{2000}\x{2001}\x{2002}\x{2003}\x{2004}\x{2005}\x{2006}\x{2007}\x{2008}\x{2009}\x{200A}\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{202F}\x{205F}\x{2060}\x{2061}\x{2062}\x{2063}\x{2064}\x{2065}\x{206A}\x{206B}\x{206C}\x{206D}\x{206E}\x{206F}\x{3000}\x{2800}\x{3164}\x{FEFF}\x{FFA0}\x{1D159}\x{1D173}\x{1D174}\x{1D175}\x{1D176}\x{1D177}\x{1D178}\x{1D179}\x{1D17A}\x{E0020}';

    /**
     * The callback that should be used to generate UUIDs.
     *
     * @var null|(Closure(): \Ramsey\Uuid\UuidInterface)
     */
    protected static ?Closure $uuidFactory = null;

    /**
     * The callback that should be used to generate ULIDs.
     *
     * @var null|(Closure(): \Symfony\Component\Uid\Ulid)
     */
    protected static ?Closure $ulidFactory = null;

    /**
     * The callback that should be used to generate random strings.
     *
     * @var null|(Closure(int): string)
     */
    protected static ?Closure $randomStringFactory = null;

    /**
     * Get a new stringable object from the given string.
     */
    public static function of(string $string): Stringable
    {
        return new Stringable($string);
    }

    /**
     * Return the remainder of a string after the first occurrence of a given value.
     */
    public static function after(string $subject, string $search): string
    {
        return $search === '' ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }

    /**
     * Return the remainder of a string after the last occurrence of a given value.
     */
    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strrpos($subject, $search);

        if ($position === false) {
            return $subject;
        }

        return substr($subject, $position + strlen($search));
    }

    /**
     * Transliterate a UTF-8 value to ASCII.
     */
    public static function ascii(string $value, string $language = 'en'): string
    {
        return ASCII::to_ascii($value, $language, replace_single_chars_only: false);
    }

    /**
     * Transliterate a string to its closest ASCII representation.
     */
    public static function transliterate(string $string, ?string $unknown = '?', ?bool $strict = false): string
    {
        return ASCII::to_transliterate($string, $unknown, $strict);
    }

    /**
     * Get the portion of a string before the first occurrence of a given value.
     */
    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $result = strstr($subject, $search, true);

        return $result === false ? $subject : $result;
    }

    /**
     * Get the portion of a string before the last occurrence of a given value.
     */
    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = mb_strrpos($subject, $search);

        if ($pos === false) {
            return $subject;
        }

        return static::substr($subject, 0, $pos);
    }

    /**
     * Get the portion of a string between two given values.
     */
    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return static::beforeLast(static::after($subject, $from), $to);
    }

    /**
     * Get the smallest possible portion of a string between two given values.
     */
    public static function betweenFirst(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return static::before(static::after($subject, $from), $to);
    }

    /**
     * Convert a value to camel case.
     */
    public static function camel(string $value): string
    {
        return lcfirst(static::studly($value));
    }

    /**
     * Get the character at the specified index.
     */
    public static function charAt(string $subject, int $index): string|false
    {
        $length = mb_strlen($subject);

        if ($index < 0 ? $index < -$length : $index > $length - 1) {
            return false;
        }

        return mb_substr($subject, $index, 1);
    }

    /**
     * Remove the given string(s) if it exists at the start of the haystack.
     */
    public static function chopStart(string $subject, string|array $needle): string
    {
        foreach ((array) $needle as $n) {
            if ($n !== '' && str_starts_with($subject, $n)) {
                return mb_substr($subject, mb_strlen($n));
            }
        }

        return $subject;
    }

    /**
     * Remove the given string(s) if it exists at the end of the haystack.
     */
    public static function chopEnd(string $subject, string|array $needle): string
    {
        foreach ((array) $needle as $n) {
            if ($n !== '' && str_ends_with($subject, $n)) {
                return mb_substr($subject, 0, -mb_strlen($n));
            }
        }

        return $subject;
    }

    /**
     * Determine if a given string contains a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public static function contains(string $haystack, string|iterable $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack);
        }

        if (! is_iterable($needles)) {
            $needles = (array) $needles;
        }

        foreach ($needles as $needle) {
            if ($ignoreCase) {
                $needle = mb_strtolower($needle);
            }

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string contains all array values.
     *
     * @param iterable<string> $needles
     */
    public static function containsAll(string $haystack, iterable $needles, bool $ignoreCase = false): bool
    {
        foreach ($needles as $needle) {
            if (! static::contains($haystack, $needle, $ignoreCase)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if a given string doesn't contain a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public static function doesntContain(string $haystack, string|iterable $needles, bool $ignoreCase = false): bool
    {
        return ! static::contains($haystack, $needles, $ignoreCase);
    }

    /**
     * Convert the case of a string.
     */
    public static function convertCase(string $string, int $mode = MB_CASE_FOLD, ?string $encoding = 'UTF-8'): string
    {
        return mb_convert_case($string, $mode, $encoding);
    }

    /**
     * Replace consecutive instances of a given character with a single character in the given string.
     *
     * @param array<string>|string $characters
     */
    public static function deduplicate(string $string, array|string $characters = ' '): string
    {
        if (is_string($characters)) {
            return preg_replace('/' . preg_quote($characters, '/') . '+/u', $characters, $string);
        }

        return array_reduce(
            $characters,
            fn ($carry, $character) => preg_replace('/' . preg_quote($character, '/') . '+/u', $character, $carry),
            $string
        );
    }

    /**
     * Determine if a given string ends with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public static function endsWith(string $haystack, string|iterable $needles): bool
    {
        if (! is_iterable($needles)) {
            $needles = (array) $needles;
        }

        foreach ($needles as $needle) {
            if ((string) $needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string doesn't end with a given substring.
     *
     * @param iterable<string>|string $needles
     */
    public static function doesntEndWith(string $haystack, string|iterable $needles): bool
    {
        return ! static::endsWith($haystack, $needles);
    }

    /**
     * Extracts an excerpt from text that matches the first instance of a phrase.
     *
     * @param array{radius?: float|int, omission?: string} $options
     */
    public static function excerpt(string $text, string $phrase = '', array $options = []): ?string
    {
        $radius = $options['radius'] ?? 100;
        $omission = $options['omission'] ?? '...';

        preg_match('/^(.*?)(' . preg_quote($phrase, '/') . ')(.*)$/iu', $text, $matches);

        if (empty($matches)) {
            return null;
        }

        $start = ltrim($matches[1]);

        $start = Str::of(mb_substr($start, max(mb_strlen($start, 'UTF-8') - $radius, 0), $radius, 'UTF-8'))->ltrim()->unless(
            fn ($startWithRadius) => $startWithRadius->exactly($start),
            fn ($startWithRadius) => $startWithRadius->prepend($omission),
        );

        $end = rtrim($matches[3]);

        $end = Str::of(mb_substr($end, 0, $radius, 'UTF-8'))->rtrim()->unless(
            fn ($endWithRadius) => $endWithRadius->exactly($end),
            fn ($endWithRadius) => $endWithRadius->append($omission),
        );

        return $start->append($matches[2], $end->toString())->toString();
    }

    /**
     * Cap a string with a single instance of a given value.
     */
    public static function finish(string $value, string $cap): string
    {
        $quoted = preg_quote($cap, '/');

        return preg_replace('/(?:' . $quoted . ')+$/u', '', $value) . $cap;
    }

    /**
     * Wrap the string with the given strings.
     */
    public static function wrap(string $value, string $before, ?string $after = null): string
    {
        return $before . $value . ($after ?? $before);
    }

    /**
     * Unwrap the string with the given strings.
     */
    public static function unwrap(string $value, string $before, ?string $after = null): string
    {
        if (static::startsWith($value, $before)) {
            $value = static::substr($value, static::length($before));
        }

        if (static::endsWith($value, $after ??= $before)) {
            $value = static::substr($value, 0, -static::length($after));
        }

        return $value;
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param iterable<string>|string $pattern
     */
    public static function is(string|iterable $pattern, string $value, bool $ignoreCase = false): bool
    {
        if (! is_iterable($pattern)) {
            $pattern = [$pattern];
        }

        foreach ($pattern as $pattern) {
            $pattern = (string) $pattern;

            // If the given value is an exact match we can of course return true right
            // from the beginning. Otherwise, we will translate asterisks and do an
            // actual pattern match against the two strings to see if they match.
            if ($pattern === '*' || $pattern === $value) {
                return true;
            }

            if ($ignoreCase && mb_strtolower($pattern) === mb_strtolower($value)) {
                return true;
            }

            $pattern = preg_quote($pattern, '#');

            // Asterisks are translated into zero-or-more regular expression wildcards
            // to make it convenient to check if the strings starts with the given
            // pattern such as "library/*", making any string check convenient.
            $pattern = str_replace('\*', '.*', $pattern);

            if (preg_match('#^' . $pattern . '\z#' . ($ignoreCase ? 'isu' : 'su'), $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string is 7 bit ASCII.
     */
    public static function isAscii(string $value): bool
    {
        return ASCII::is_ascii($value);
    }

    /**
     * Determine if a given value is valid JSON.
     */
    public static function isJson(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return json_validate($value, 512);
    }

    /**
     * Determine if a given value is a valid URL.
     *
     * @param string[] $protocols
     */
    public static function isUrl(mixed $value, array $protocols = []): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $protocolList = empty($protocols)
            ? 'aaa|aaas|about|acap|acct|acd|acr|adiumxtra|adt|afp|afs|aim|amss|android|appdata|apt|ark|attachment|aw|barion|beshare|bitcoin|bitcoincash|blob|bolo|browserext|calculator|callto|cap|cast|casts|chrome|chrome-extension|cid|coap|coap\+tcp|coap\+ws|coaps|coaps\+tcp|coaps\+ws|com-eventbrite-attendee|content|conti|crid|cvs|dab|data|dav|diaspora|dict|did|dis|dlna-playcontainer|dlna-playsingle|dns|dntp|dpp|drm|drop|dtn|dvb|ed2k|elsi|example|facetime|fax|feed|feedready|file|filesystem|finger|first-run-pen-experience|fish|fm|ftp|fuchsia-pkg|geo|gg|git|gizmoproject|go|gopher|graph|gtalk|h323|ham|hcap|hcp|http|https|hxxp|hxxps|hydrazone|iax|icap|icon|im|imap|info|iotdisco|ipn|ipp|ipps|irc|irc6|ircs|iris|iris\.beep|iris\.lwz|iris\.xpc|iris\.xpcs|isostore|itms|jabber|jar|jms|keyparc|lastfm|ldap|ldaps|leaptofrogans|lorawan|lvlt|magnet|mailserver|mailto|maps|market|message|mid|mms|modem|mongodb|moz|ms-access|ms-browser-extension|ms-calculator|ms-drive-to|ms-enrollment|ms-excel|ms-eyecontrolspeech|ms-gamebarservices|ms-gamingoverlay|ms-getoffice|ms-help|ms-infopath|ms-inputapp|ms-lockscreencomponent-config|ms-media-stream-id|ms-mixedrealitycapture|ms-mobileplans|ms-officeapp|ms-people|ms-project|ms-powerpoint|ms-publisher|ms-restoretabcompanion|ms-screenclip|ms-screensketch|ms-search|ms-search-repair|ms-secondary-screen-controller|ms-secondary-screen-setup|ms-settings|ms-settings-airplanemode|ms-settings-bluetooth|ms-settings-camera|ms-settings-cellular|ms-settings-cloudstorage|ms-settings-connectabledevices|ms-settings-displays-topology|ms-settings-emailandaccounts|ms-settings-language|ms-settings-location|ms-settings-lock|ms-settings-nfctransactions|ms-settings-notifications|ms-settings-power|ms-settings-privacy|ms-settings-proximity|ms-settings-screenrotation|ms-settings-wifi|ms-settings-workplace|ms-spd|ms-sttoverlay|ms-transit-to|ms-useractivityset|ms-virtualtouchpad|ms-visio|ms-walk-to|ms-whiteboard|ms-whiteboard-cmd|ms-word|msnim|msrp|msrps|mss|mtqp|mumble|mupdate|mvn|news|nfs|ni|nih|nntp|notes|ocf|oid|onenote|onenote-cmd|opaquelocktoken|openpgp4fpr|pack|palm|paparazzi|payto|pkcs11|platform|pop|pres|prospero|proxy|pwid|psyc|pttp|qb|query|redis|rediss|reload|res|resource|rmi|rsync|rtmfp|rtmp|rtsp|rtsps|rtspu|s3|secondlife|service|session|sftp|sgn|shttp|sieve|simpleledger|sip|sips|skype|smb|sms|smtp|snews|snmp|soap\.beep|soap\.beeps|soldat|spiffe|spotify|ssh|steam|stun|stuns|submit|svn|tag|teamspeak|tel|teliaeid|telnet|tftp|tg|things|thismessage|tip|tn3270|tool|ts3server|turn|turns|tv|udp|unreal|urn|ut2004|v-event|vemmi|ventrilo|videotex|vnc|view-source|wais|webcal|wpid|ws|wss|wtai|wyciwyg|xcon|xcon-userid|xfire|xmlrpc\.beep|xmlrpc\.beeps|xmpp|xri|ymsgr|z39\.50|z39\.50r|z39\.50s'
            : implode('|', $protocols);

        /*
         * This pattern is derived from Symfony\Component\Validator\Constraints\UrlValidator (5.0.7).
         *
         * (c) Fabien Potencier <fabien@symfony.com> http://symfony.com
         */
        $pattern = '~^
            (LARAVEL_PROTOCOLS)://                                 # protocol
            (((?:[\_\.\pL\pN-]|%[0-9A-Fa-f]{2})+:)?((?:[\_\.\pL\pN-]|%[0-9A-Fa-f]{2})+)@)?  # basic auth
            (
                ([\pL\pN\pS\-\_\.])+(\.?([\pL\pN]|xn\-\-[\pL\pN-]+)+\.?) # a domain name
                    |                                                 # or
                \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}                    # an IP address
                    |                                                 # or
                \[
                    (?:(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){6})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:::(?:(?:(?:[0-9a-f]{1,4})):){5})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){4})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,1}(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){3})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,2}(?:(?:[0-9a-f]{1,4})))?::(?:(?:(?:[0-9a-f]{1,4})):){2})(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,3}(?:(?:[0-9a-f]{1,4})))?::(?:(?:[0-9a-f]{1,4})):)(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,4}(?:(?:[0-9a-f]{1,4})))?::)(?:(?:(?:(?:(?:[0-9a-f]{1,4})):(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9]))\.){3}(?:(?:25[0-5]|(?:[1-9]|1[0-9]|2[0-4])?[0-9])))))))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,5}(?:(?:[0-9a-f]{1,4})))?::)(?:(?:[0-9a-f]{1,4})))|(?:(?:(?:(?:(?:(?:[0-9a-f]{1,4})):){0,6}(?:(?:[0-9a-f]{1,4})))?::))))
                \]  # an IPv6 address
            )
            (:[0-9]+)?                              # a port (optional)
            (?:/ (?:[\pL\pN\-._\~!$&\'()*+,;=:@]|%[0-9A-Fa-f]{2})* )*          # a path
            (?:\? (?:[\pL\pN\-._\~!$&\'\[\]()*+,;=:@/?]|%[0-9A-Fa-f]{2})* )?   # a query (optional)
            (?:\# (?:[\pL\pN\-._\~!$&\'()*+,;=:@/?]|%[0-9A-Fa-f]{2})* )?       # a fragment (optional)
        $~ixu';

        return preg_match(str_replace('LARAVEL_PROTOCOLS', $protocolList, $pattern), $value) > 0;
    }

    /**
     * Determine if a given value is a valid UUID.
     *
     * @param null|'max'|'nil'|int<0, 8> $version
     */
    public static function isUuid(mixed $value, int|string|null $version = null): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if ($version === null) {
            return preg_match('/^[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}$/D', $value) > 0;
        }

        $factory = new UuidFactory();

        try {
            $factoryUuid = $factory->fromString($value);
        } catch (InvalidUuidStringException) {
            return false;
        }

        $fields = $factoryUuid->getFields();

        if (! $fields instanceof FieldsInterface) {
            return false;
        }

        if ($version === 0 || $version === 'nil') {
            return $fields->isNil();
        }

        if ($version === 'max') {
            return $fields->isMax(); // @phpstan-ignore method.notFound (method exists on concrete class, not interface)
        }

        return $fields->getVersion() === $version;
    }

    /**
     * Determine if a given value is a valid ULID.
     */
    public static function isUlid(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return Ulid::isValid($value);
    }

    /**
     * Convert a string to kebab case.
     */
    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    /**
     * Return the length of the given string.
     */
    public static function length(string $value, ?string $encoding = null): int
    {
        return mb_strlen($value, $encoding);
    }

    /**
     * Limit the number of characters in a string.
     */
    public static function limit(string $value, int $limit = 100, string $end = '...', bool $preserveWords = false): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }

        if (! $preserveWords) {
            return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
        }

        $value = trim(preg_replace('/[\n\r]+/', ' ', strip_tags($value)));

        $trimmed = rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8'));

        if (mb_substr($value, $limit, 1, 'UTF-8') === ' ') {
            return $trimmed . $end;
        }

        return preg_replace('/(.*)\s.*/', '$1', $trimmed) . $end;
    }

    /**
     * Convert the given string to lower-case.
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Limit the number of words in a string.
     */
    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . $words . '}/u', $value, $matches);

        if (! isset($matches[0]) || static::length($value) === static::length($matches[0])) {
            return $value;
        }

        return rtrim($matches[0]) . $end;
    }

    /**
     * Converts GitHub flavored Markdown into HTML.
     *
     * @param \League\CommonMark\Extension\ExtensionInterface[] $extensions
     */
    public static function markdown(string $string, array $options = [], array $extensions = []): string
    {
        $converter = new GithubFlavoredMarkdownConverter($options);

        $environment = $converter->getEnvironment();

        foreach ($extensions as $extension) {
            $environment->addExtension($extension);
        }

        return (string) $converter->convert($string);
    }

    /**
     * Converts inline Markdown into HTML.
     *
     * @param \League\CommonMark\Extension\ExtensionInterface[] $extensions
     */
    public static function inlineMarkdown(string $string, array $options = [], array $extensions = []): string
    {
        $environment = new Environment($options);

        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new InlinesOnlyExtension());

        foreach ($extensions as $extension) {
            $environment->addExtension($extension);
        }

        $converter = new MarkdownConverter($environment);

        return (string) $converter->convert($string);
    }

    /**
     * Masks a portion of a string with a repeated character.
     */
    public static function mask(string $string, string $character, int $index, ?int $length = null, string $encoding = 'UTF-8'): string
    {
        if ($character === '') {
            return $string;
        }

        $segment = mb_substr($string, $index, $length, $encoding);

        if ($segment === '') {
            return $string;
        }

        $strlen = mb_strlen($string, $encoding);
        $startIndex = $index;

        if ($index < 0) {
            $startIndex = $index < -$strlen ? 0 : $strlen + $index;
        }

        $start = mb_substr($string, 0, $startIndex, $encoding);
        $segmentLen = mb_strlen($segment, $encoding);
        $end = mb_substr($string, $startIndex + $segmentLen);

        return $start . str_repeat(mb_substr($character, 0, 1, $encoding), $segmentLen) . $end;
    }

    /**
     * Get the string matching the given pattern.
     */
    public static function match(string $pattern, string $subject): string
    {
        preg_match($pattern, $subject, $matches);

        if (! $matches) {
            return '';
        }

        return $matches[1] ?? $matches[0];
    }

    /**
     * Determine if a given string matches a given pattern.
     *
     * @param iterable<string>|string $pattern
     */
    public static function isMatch(string|iterable $pattern, string $value): bool
    {
        if (! is_iterable($pattern)) {
            $pattern = [$pattern];
        }

        foreach ($pattern as $pattern) {
            $pattern = (string) $pattern;

            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the string matching the given pattern.
     */
    public static function matchAll(string $pattern, string $subject): Collection
    {
        preg_match_all($pattern, $subject, $matches);

        if (empty($matches[0])) {
            return new Collection();
        }

        return new Collection($matches[1] ?? $matches[0]);
    }

    /**
     * Remove all non-numeric characters from a string.
     */
    public static function numbers(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value);
    }

    /**
     * Pad both sides of a string with another.
     */
    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        return mb_str_pad($value, $length, $pad, STR_PAD_BOTH);
    }

    /**
     * Pad the left side of a string with another.
     */
    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        return mb_str_pad($value, $length, $pad, STR_PAD_LEFT);
    }

    /**
     * Pad the right side of a string with another.
     */
    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        return mb_str_pad($value, $length, $pad, STR_PAD_RIGHT);
    }

    /**
     * Parse a Class[@]method style callback into class and method.
     *
     * @return array<int, null|string>
     */
    public static function parseCallback(string $callback, ?string $default = null): array
    {
        if (static::contains($callback, "@anonymous\0")) {
            if (static::substrCount($callback, '@') > 1) {
                return [
                    static::beforeLast($callback, '@'),
                    static::afterLast($callback, '@'),
                ];
            }

            return [$callback, $default];
        }

        return static::contains($callback, '@') ? explode('@', $callback, 2) : [$callback, $default];
    }

    /**
     * Get the plural form of an English word.
     */
    public static function plural(string $value, int|array|Countable $count = 2, bool $prependCount = false): string
    {
        if (is_countable($count)) {
            $count = count($count);
        }

        return ($prependCount ? Number::format($count) . ' ' : '') . Pluralizer::plural($value, $count);
    }

    /**
     * Pluralize the last word of an English, studly caps case string.
     */
    public static function pluralStudly(string $value, int|array|Countable $count = 2): string
    {
        $parts = preg_split('/(.)(?=[A-Z])/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        $lastWord = array_pop($parts);

        return implode('', $parts) . self::plural($lastWord, $count);
    }

    /**
     * Pluralize the last word of an English, Pascal caps case string.
     */
    public static function pluralPascal(string $value, int|array|Countable $count = 2): string
    {
        return static::pluralStudly($value, $count);
    }

    /**
     * Generate a random, secure password.
     */
    public static function password(int $length = 32, bool $letters = true, bool $numbers = true, bool $symbols = true, bool $spaces = false): string
    {
        $password = new Collection();

        $options = (new Collection([
            'letters' => $letters === true ? [
                'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k',
                'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v',
                'w', 'x', 'y', 'z', 'A', 'B', 'C', 'D', 'E', 'F', 'G',
                'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R',
                'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
            ] : null,
            'numbers' => $numbers === true ? [
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
            ] : null,
            'symbols' => $symbols === true ? [
                '~', '!', '#', '$', '%', '^', '&', '*', '(', ')', '-',
                '_', '.', ',', '<', '>', '?', '/', '\\', '{', '}', '[',
                ']', '|', ':', ';',
            ] : null,
            'spaces' => $spaces === true ? [' '] : null,
        ]))
            ->filter()
            ->each(fn ($c) => $password->push($c[random_int(0, count($c) - 1)]))
            ->flatten();

        $length = $length - $password->count();

        return $password->merge($options->pipe(
            fn ($c) => Collection::times($length, fn () => $c[random_int(0, $c->count() - 1)]) // @phpstan-ignore argument.type, return.type
        ))->shuffle()->implode('');
    }

    /**
     * Find the multi-byte safe position of the first occurrence of a given substring in a string.
     */
    public static function position(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return mb_strpos($haystack, $needle, $offset, $encoding);
    }

    /**
     * Generate a more truly "random" alpha-numeric string.
     */
    public static function random(int $length = 16): string
    {
        return (static::$randomStringFactory ?? function ($length) {
            $string = '';

            while (($len = strlen($string)) < $length) {
                $size = $length - $len;

                $bytesSize = (int) ceil($size / 3) * 3;

                $bytes = random_bytes($bytesSize);

                $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
            }

            return $string;
        })($length);
    }

    /**
     * Set the callable that will be used to generate random strings.
     *
     * @param null|(callable(int): string) $factory
     */
    public static function createRandomStringsUsing(?callable $factory = null): void
    {
        static::$randomStringFactory = $factory;
    }

    /**
     * Set the sequence that will be used to generate random strings.
     *
     * @param string[] $sequence
     * @param null|(callable(int): string) $whenMissing
     */
    public static function createRandomStringsUsingSequence(array $sequence, ?callable $whenMissing = null): void
    {
        $next = 0;

        $whenMissing ??= function ($length) use (&$next) {
            $factoryCache = static::$randomStringFactory;

            static::$randomStringFactory = null;

            $randomString = static::random($length);

            static::$randomStringFactory = $factoryCache;

            ++$next;

            return $randomString;
        };

        static::createRandomStringsUsing(function ($length) use (&$next, $sequence, $whenMissing) {
            if (array_key_exists($next, $sequence)) {
                return $sequence[$next++];
            }

            return $whenMissing($length);
        });
    }

    /**
     * Indicate that random strings should be created normally and not using a custom factory.
     */
    public static function createRandomStringsNormally(): void
    {
        static::$randomStringFactory = null;
    }

    /**
     * Repeat the given string.
     */
    public static function repeat(string $string, int $times): string
    {
        return str_repeat($string, $times);
    }

    /**
     * Replace a given value in the string sequentially with an array.
     *
     * @param iterable<string> $replace
     */
    public static function replaceArray(string $search, iterable $replace, string $subject): string
    {
        if ($replace instanceof Traversable) {
            $replace = Arr::from($replace);
        }

        $segments = explode($search, $subject);

        $result = array_shift($segments);

        foreach ($segments as $segment) {
            $result .= self::toStringOr(array_shift($replace) ?? $search, $search) . $segment;
        }

        return $result;
    }

    /**
     * Convert the given value to a string or return the given fallback on failure.
     */
    private static function toStringOr(mixed $value, string $fallback): string
    {
        try {
            return (string) $value;
        } catch (Throwable) { // @phpstan-ignore catch.neverThrown (__toString can throw)
            return $fallback;
        }
    }

    /**
     * Replace the given value in the given string.
     *
     * @param iterable<string>|string $search
     * @param iterable<string>|string $replace
     * @param iterable<string>|string $subject
     */
    public static function replace(string|iterable $search, string|iterable $replace, string|iterable $subject, bool $caseSensitive = true): string|array
    {
        if ($search instanceof Traversable) {
            $search = Arr::from($search);
        }

        if ($replace instanceof Traversable) {
            $replace = Arr::from($replace);
        }

        if ($subject instanceof Traversable) {
            $subject = Arr::from($subject);
        }

        return $caseSensitive
            ? str_replace($search, $replace, $subject)
            : str_ireplace($search, $replace, $subject);
    }

    /**
     * Replace the first occurrence of a given value in the string.
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }

    /**
     * Replace the first occurrence of the given value if it appears at the start of the string.
     */
    public static function replaceStart(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        if (static::startsWith($subject, $search)) {
            return static::replaceFirst($search, $replace, $subject);
        }

        return $subject;
    }

    /**
     * Replace the last occurrence of a given value in the string.
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strrpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }

    /**
     * Replace the last occurrence of a given value if it appears at the end of the string.
     */
    public static function replaceEnd(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        if (static::endsWith($subject, $search)) {
            return static::replaceLast($search, $replace, $subject);
        }

        return $subject;
    }

    /**
     * Replace the patterns matching the given regular expression.
     *
     * @param string|string[] $pattern
     * @param (Closure(array): string)|string|string[] $replace
     * @param string|string[] $subject
     */
    public static function replaceMatches(string|array $pattern, Closure|array|string $replace, string|array $subject, int $limit = -1): string|array|null
    {
        if ($replace instanceof Closure) {
            return preg_replace_callback($pattern, $replace, $subject, $limit);
        }

        return preg_replace($pattern, $replace, $subject, $limit);
    }

    /**
     * Remove any occurrence of the given string in the subject.
     *
     * @param iterable<string>|string $search
     */
    public static function remove(string|iterable $search, string $subject, bool $caseSensitive = true): string
    {
        if ($search instanceof Traversable) {
            $search = Arr::from($search);
        }

        return $caseSensitive
            ? str_replace($search, '', $subject)
            : str_ireplace($search, '', $subject);
    }

    /**
     * Reverse the given string.
     */
    public static function reverse(string $value): string
    {
        return implode(array_reverse(mb_str_split($value)));
    }

    /**
     * Begin a string with a single instance of a given value.
     */
    public static function start(string $value, string $prefix): string
    {
        $quoted = preg_quote($prefix, '/');

        return $prefix . preg_replace('/^(?:' . $quoted . ')+/u', '', $value);
    }

    /**
     * Convert the given string to upper-case.
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Convert the given string to proper case.
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Convert the given string to proper case for each word.
     */
    public static function headline(string $value): string
    {
        $parts = mb_split('\s+', $value);

        $parts = count($parts) > 1
            ? array_map(static::title(...), $parts)
            : array_map(static::title(...), static::ucsplit(implode('_', $parts)));

        $collapsed = static::replace(['-', '_', ' '], '_', implode('_', $parts));

        return implode(' ', array_filter(explode('_', $collapsed)));
    }

    /**
     * Convert the given string to APA-style title case.
     *
     * See: https://apastyle.apa.org/style-grammar-guidelines/capitalization/title-case
     */
    public static function apa(string $value): string
    {
        if (trim($value) === '') {
            return $value;
        }

        $minorWords = [
            'and', 'as', 'but', 'for', 'if', 'nor', 'or', 'so', 'yet', 'a', 'an',
            'the', 'at', 'by', 'in', 'of', 'off', 'on', 'per', 'to', 'up', 'via',
            'et', 'ou', 'un', 'une', 'la', 'le', 'les', 'de', 'du', 'des', 'par', 'à',
        ];

        $endPunctuation = ['.', '!', '?', ':', '—', ','];

        $words = mb_split('\s+', $value);
        $wordCount = count($words);

        for ($i = 0; $i < $wordCount; ++$i) {
            $lowercaseWord = mb_strtolower($words[$i]);

            if (str_contains($lowercaseWord, '-')) {
                $hyphenatedWords = explode('-', $lowercaseWord);

                $hyphenatedWords = array_map(function ($part) use ($minorWords) {
                    // @phpstan-ignore smallerOrEqual.alwaysTrue (defensive check)
                    return (in_array($part, $minorWords) && mb_strlen($part) <= 3)
                        ? $part
                        : mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1);
                }, $hyphenatedWords);

                $words[$i] = implode('-', $hyphenatedWords);
            } else {
                if (in_array($lowercaseWord, $minorWords)
                    && mb_strlen($lowercaseWord) <= 3 // @phpstan-ignore smallerOrEqual.alwaysTrue
                    && ! ($i === 0 || in_array(mb_substr($words[$i - 1], -1), $endPunctuation))) {
                    $words[$i] = $lowercaseWord;
                } else {
                    $words[$i] = mb_strtoupper(mb_substr($lowercaseWord, 0, 1)) . mb_substr($lowercaseWord, 1);
                }
            }
        }

        return implode(' ', $words);
    }

    /**
     * Get the singular form of an English word.
     */
    public static function singular(string $value): string
    {
        return Pluralizer::singular($value);
    }

    /**
     * Generate a URL friendly "slug" from a given string.
     *
     * @param array<string, string> $dictionary
     */
    public static function slug(string $title, string $separator = '-', ?string $language = 'en', array $dictionary = ['@' => 'at']): string
    {
        $title = $language ? static::ascii($title, $language) : $title;

        // Convert all dashes/underscores into separator
        $flip = $separator === '-' ? '_' : '-';

        $title = preg_replace('![' . preg_quote($flip) . ']+!u', $separator, $title);

        // Replace dictionary words
        foreach ($dictionary as $key => $value) {
            $dictionary[$key] = $separator . $value . $separator;
        }

        $title = str_replace(array_keys($dictionary), array_values($dictionary), $title);

        // Remove all characters that are not the separator, letters, numbers, or whitespace
        $title = preg_replace('![^' . preg_quote($separator) . '\pL\pN\s]+!u', '', static::lower($title));

        // Replace all separator characters and whitespace by a single separator
        $title = preg_replace('![' . preg_quote($separator) . '\s]+!u', $separator, $title);

        return trim($title, $separator);
    }

    /**
     * Convert a string to snake case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (! ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));

            $value = static::lower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
        }

        return $value;
    }

    /**
     * Remove all whitespace from both ends of a string.
     */
    public static function trim(string $value, ?string $charlist = null): string
    {
        if ($charlist === null) {
            $trimDefaultCharacters = " \n\r\t\v\0";

            return preg_replace('~^[\s' . self::INVISIBLE_CHARACTERS . $trimDefaultCharacters . ']+|[\s' . self::INVISIBLE_CHARACTERS . $trimDefaultCharacters . ']+$~u', '', $value) ?? trim($value);
        }

        return trim($value, $charlist);
    }

    /**
     * Remove all whitespace from the beginning of a string.
     */
    public static function ltrim(string $value, ?string $charlist = null): string
    {
        if ($charlist === null) {
            $ltrimDefaultCharacters = " \n\r\t\v\0";

            return preg_replace('~^[\s' . self::INVISIBLE_CHARACTERS . $ltrimDefaultCharacters . ']+~u', '', $value) ?? ltrim($value);
        }

        return ltrim($value, $charlist);
    }

    /**
     * Remove all whitespace from the end of a string.
     */
    public static function rtrim(string $value, ?string $charlist = null): string
    {
        if ($charlist === null) {
            $rtrimDefaultCharacters = " \n\r\t\v\0";

            return preg_replace('~[\s' . self::INVISIBLE_CHARACTERS . $rtrimDefaultCharacters . ']+$~u', '', $value) ?? rtrim($value);
        }

        return rtrim($value, $charlist);
    }

    /**
     * Remove all "extra" blank space from the given string.
     */
    public static function squish(string $value): string
    {
        return preg_replace('~(\s|\x{3164}|\x{1160})+~u', ' ', static::trim($value));
    }

    /**
     * Determine if a given string starts with a given substring.
     *
     * @param iterable<string>|string $needles
     * @return ($needles is array{} ? false : ($haystack is non-empty-string ? bool : false))
     *
     * @phpstan-assert-if-true =non-empty-string $haystack
     */
    public static function startsWith(string $haystack, string|iterable $needles): bool
    {
        if (! is_iterable($needles)) {
            $needles = [$needles];
        }

        foreach ($needles as $needle) {
            if ((string) $needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string doesn't start with a given substring.
     *
     * @param iterable<string>|string $needles
     * @return ($needles is array{} ? true : ($haystack is non-empty-string ? bool : true))
     *
     * @phpstan-assert-if-false =non-empty-string $haystack
     */
    public static function doesntStartWith(string $haystack, string|iterable $needles): bool
    {
        return ! static::startsWith($haystack, $needles);
    }

    /**
     * Convert a value to studly caps case.
     *
     * @return ($value is '' ? '' : string)
     */
    public static function studly(string $value): string
    {
        $words = mb_split('\s+', static::replace(['-', '_'], ' ', $value));

        $studlyWords = array_map(fn ($word) => static::ucfirst($word), $words);

        return implode($studlyWords);
    }

    /**
     * Convert a value to Pascal case.
     *
     * @return ($value is '' ? '' : string)
     */
    public static function pascal(string $value): string
    {
        return static::studly($value);
    }

    /**
     * Returns the portion of the string specified by the start and length parameters.
     */
    public static function substr(string $string, int $start, ?int $length = null, string $encoding = 'UTF-8'): string
    {
        return mb_substr($string, $start, $length, $encoding);
    }

    /**
     * Returns the number of substring occurrences.
     */
    public static function substrCount(string $haystack, string $needle, int $offset = 0, ?int $length = null): int
    {
        if (! is_null($length)) {
            return substr_count($haystack, $needle, $offset, $length);
        }

        return substr_count($haystack, $needle, $offset);
    }

    /**
     * Replace text within a portion of a string.
     *
     * @param string|string[] $string
     * @param string|string[] $replace
     * @param int|int[] $offset
     * @param null|int|int[] $length
     * @return string|string[]
     */
    public static function substrReplace(string|array $string, string|array $replace, int|array $offset = 0, int|array|null $length = null): string|array
    {
        if ($length === null) {
            $length = static::length($string);
        }

        return mb_substr($string, 0, $offset)
            . $replace
            . mb_substr($string, $offset + $length);
    }

    /**
     * Swap multiple keywords in a string with other keywords.
     *
     * @param array<string, string> $map
     */
    public static function swap(array $map, string $subject): string
    {
        return strtr($subject, $map);
    }

    /**
     * Take the first or last {$limit} characters of a string.
     */
    public static function take(string $string, int $limit): string
    {
        if ($limit < 0) {
            return static::substr($string, $limit);
        }

        return static::substr($string, 0, $limit);
    }

    /**
     * Convert the given string to Base64 encoding.
     *
     * @return ($string is '' ? '' : string)
     */
    public static function toBase64(string $string): string
    {
        return base64_encode($string);
    }

    /**
     * Decode the given Base64 encoded string.
     *
     * @return ($strict is true ? ($string is '' ? '' : false|string) : ($string is '' ? '' : string))
     */
    public static function fromBase64(string $string, bool $strict = false): string|false
    {
        return base64_decode($string, $strict);
    }

    /**
     * Make a string's first character lowercase.
     *
     * @return ($string is '' ? '' : non-empty-string)
     */
    public static function lcfirst(string $string): string
    {
        return static::lower(static::substr($string, 0, 1)) . static::substr($string, 1);
    }

    /**
     * Make a string's first character uppercase.
     *
     * @return ($string is '' ? '' : non-empty-string)
     */
    public static function ucfirst(string $string): string
    {
        return static::upper(static::substr($string, 0, 1)) . static::substr($string, 1);
    }

    /**
     * Capitalize the first character of each word in a string.
     *
     * @return ($string is '' ? '' : non-empty-string)
     */
    public static function ucwords(string $string, string $separators = " \t\r\n\f\v"): string
    {
        $pattern = '/(^|[' . preg_quote($separators, '/') . '])(\p{Ll})/u';

        return preg_replace_callback($pattern, function ($matches) {
            return $matches[1] . mb_strtoupper($matches[2]);
        }, $string);
    }

    /**
     * Split a string into pieces by uppercase characters.
     *
     * @return ($string is '' ? array{} : string[])
     */
    public static function ucsplit(string $string): array
    {
        return preg_split('/(?=\p{Lu})/u', $string, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Get the number of words a string contains.
     *
     * @return non-negative-int
     */
    public static function wordCount(string $string, ?string $characters = null): int
    {
        return str_word_count($string, 0, $characters);
    }

    /**
     * Wrap a string to a given number of characters.
     */
    public static function wordWrap(string $string, int $characters = 75, string $break = "\n", bool $cutLongWords = false): string
    {
        return wordwrap($string, $characters, $break, $cutLongWords);
    }

    /**
     * Generate a UUID (version 4).
     */
    public static function uuid(): UuidInterface
    {
        return static::$uuidFactory
            ? call_user_func(static::$uuidFactory)
            : Uuid::uuid4();
    }

    /**
     * Generate a UUID (version 7).
     */
    public static function uuid7(?DateTimeInterface $time = null): UuidInterface
    {
        return static::$uuidFactory
            ? call_user_func(static::$uuidFactory)
            : Uuid::uuid7($time);
    }

    /**
     * Generate a time-ordered UUID.
     */
    public static function orderedUuid(): UuidInterface
    {
        if (static::$uuidFactory) {
            return call_user_func(static::$uuidFactory);
        }

        $factory = new UuidFactory();

        $factory->setRandomGenerator(new CombGenerator(
            $factory->getRandomGenerator(),
            $factory->getNumberConverter()
        ));

        $factory->setCodec(new TimestampFirstCombCodec(
            $factory->getUuidBuilder()
        ));

        return $factory->uuid4();
    }

    /**
     * Set the callable that will be used to generate UUIDs.
     *
     * @param null|(callable(): UuidInterface) $factory
     */
    public static function createUuidsUsing(?callable $factory = null): void
    {
        static::$uuidFactory = $factory;
    }

    /**
     * Set the sequence that will be used to generate UUIDs.
     *
     * @param UuidInterface[] $sequence
     * @param null|(callable(): UuidInterface) $whenMissing
     */
    public static function createUuidsUsingSequence(array $sequence, ?callable $whenMissing = null): void
    {
        $next = 0;

        $whenMissing ??= function () use (&$next) {
            $factoryCache = static::$uuidFactory;

            static::$uuidFactory = null;

            $uuid = static::uuid();

            static::$uuidFactory = $factoryCache;

            ++$next;

            return $uuid;
        };

        static::createUuidsUsing(function () use (&$next, $sequence, $whenMissing) {
            if (array_key_exists($next, $sequence)) {
                return $sequence[$next++];
            }

            return $whenMissing();
        });
    }

    /**
     * Always return the same UUID when generating new UUIDs.
     *
     * @param null|(Closure(UuidInterface): mixed) $callback
     */
    public static function freezeUuids(?Closure $callback = null): UuidInterface
    {
        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        if ($callback !== null) {
            try {
                $callback($uuid);
            } finally {
                Str::createUuidsNormally();
            }
        }

        return $uuid;
    }

    /**
     * Indicate that UUIDs should be created normally and not using a custom factory.
     */
    public static function createUuidsNormally(): void
    {
        static::$uuidFactory = null;
    }

    /**
     * Generate a ULID.
     */
    public static function ulid(?DateTimeInterface $time = null): Ulid
    {
        if (static::$ulidFactory) {
            return call_user_func(static::$ulidFactory);
        }

        if ($time === null) {
            return new Ulid();
        }

        return new Ulid(Ulid::generate($time));
    }

    /**
     * Indicate that ULIDs should be created normally and not using a custom factory.
     */
    public static function createUlidsNormally(): void
    {
        static::$ulidFactory = null;
    }

    /**
     * Set the callable that will be used to generate ULIDs.
     *
     * @param null|(callable(): Ulid) $factory
     */
    public static function createUlidsUsing(?callable $factory = null): void
    {
        static::$ulidFactory = $factory;
    }

    /**
     * Set the sequence that will be used to generate ULIDs.
     *
     * @param Ulid[] $sequence
     * @param null|(callable(): Ulid) $whenMissing
     */
    public static function createUlidsUsingSequence(array $sequence, ?callable $whenMissing = null): void
    {
        $next = 0;

        $whenMissing ??= function () use (&$next) {
            $factoryCache = static::$ulidFactory;

            static::$ulidFactory = null;

            $ulid = static::ulid();

            static::$ulidFactory = $factoryCache;

            ++$next;

            return $ulid;
        };

        static::createUlidsUsing(function () use (&$next, $sequence, $whenMissing) {
            if (array_key_exists($next, $sequence)) {
                return $sequence[$next++];
            }

            return $whenMissing();
        });
    }

    /**
     * Always return the same ULID when generating new ULIDs.
     *
     * @param null|(Closure(Ulid): mixed) $callback
     */
    public static function freezeUlids(?Closure $callback = null): Ulid
    {
        $ulid = Str::ulid();

        Str::createUlidsUsing(fn () => $ulid);

        if ($callback !== null) {
            try {
                $callback($ulid);
            } finally {
                Str::createUlidsNormally();
            }
        }

        return $ulid;
    }
}
