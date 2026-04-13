<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use Closure;
use Hypervel\Contracts\Support\DeferringDisplayableValue;
use Hypervel\Contracts\Support\Htmlable;
use Override;

class EncodedHtmlString extends HtmlString
{
    /**
     * The callback that should be used to encode the HTML strings.
     */
    protected static ?Closure $encodeUsingFactory = null;

    /**
     * Create a new encoded HTML string instance.
     */
    public function __construct(mixed $html = '', protected bool $doubleEncode = true)
    {
        parent::__construct($html);
    }

    /**
     * Convert the special characters in the given value.
     *
     * @internal
     */
    public static function convert(?string $value, bool $withQuote = true, bool $doubleEncode = true): string
    {
        $flag = $withQuote ? ENT_QUOTES : ENT_NOQUOTES;

        return htmlspecialchars($value ?? '', $flag | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
    }

    /**
     * Get the HTML string.
     */
    #[Override]
    public function toHtml(): string
    {
        $value = $this->html;

        if ($value instanceof DeferringDisplayableValue) {
            $value = $value->resolveDisplayableValue();
        }

        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return (static::$encodeUsingFactory ?? function ($value, $doubleEncode) {
            return static::convert($value, doubleEncode: $doubleEncode);
        })($value, $this->doubleEncode);
    }

    /**
     * Set the callable that will be used to encode the HTML strings.
     */
    public static function encodeUsing(?callable $factory = null): void
    {
        static::$encodeUsingFactory = $factory;
    }

    /**
     * Flush the class's global state.
     */
    public static function flushState(): void
    {
        static::$encodeUsingFactory = null;
    }
}
