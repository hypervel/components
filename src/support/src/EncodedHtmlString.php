<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Support\DeferringDisplayableValue;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Support\Attributes\BootOnly;
use Override;

class EncodedHtmlString extends HtmlString
{
    /**
     * Context key for temporarily scoped encoder callbacks.
     */
    protected const string ENCODER_CONTEXT_KEY = '__support.encoded_html.encoder';

    /**
     * Context key for the marker kept while scoped encoder callbacks may be in use.
     */
    protected const string ENCODER_MARKER_CONTEXT_KEY = '__support.encoded_html.encoder_marker';

    /**
     * The callback that should be used to encode the HTML strings.
     */
    protected static ?Closure $encodeUsingFactory = null;

    /**
     * The marker kept while the boot encoder is registered.
     */
    protected static ?HtmlEncoderMarker $encodeUsingMarker = null;

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
     * Encode the given value using the active encoder.
     *
     * @internal
     */
    public static function encode(mixed $value, bool $doubleEncode = true): string
    {
        $encoder = CoroutineContext::get(self::ENCODER_CONTEXT_KEY) ?? static::$encodeUsingFactory;

        return $encoder === null
            ? static::convert((string) ($value ?? ''), doubleEncode: $doubleEncode)
            : $encoder($value, $doubleEncode);
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

        return static::encode($value, $this->doubleEncode);
    }

    /**
     * Set the callable that will be used to encode the HTML strings.
     *
     * The factory persists in a static property for the worker lifetime
     * and applies to every encoded HTML string.
     */
    #[BootOnly]
    public static function encodeUsing(?callable $factory = null): void
    {
        static::$encodeUsingFactory = $factory === null ? null : Closure::fromCallable($factory);
        static::$encodeUsingMarker = $factory === null ? null : new HtmlEncoderMarker;
    }

    /**
     * Execute the given callback using a temporary encoder.
     */
    public static function withEncoding(callable $factory, callable $callback): mixed
    {
        // Preserve an outer scoped encoder so nested Markdown renders restore
        // the previous coroutine-local state instead of falling back globally.
        $hadPreviousFactory = CoroutineContext::has(self::ENCODER_CONTEXT_KEY);
        $previousFactory = CoroutineContext::get(self::ENCODER_CONTEXT_KEY);
        $hadMarker = CoroutineContext::has(self::ENCODER_MARKER_CONTEXT_KEY);

        CoroutineContext::set(self::ENCODER_CONTEXT_KEY, $factory);

        // Forked coroutines share the marker, so e() keeps looking up encoders until every copy has finished.
        if (! $hadMarker) {
            CoroutineContext::set(self::ENCODER_MARKER_CONTEXT_KEY, new HtmlEncoderMarker);
        }

        try {
            return $callback();
        } finally {
            if ($hadPreviousFactory) {
                CoroutineContext::set(self::ENCODER_CONTEXT_KEY, $previousFactory);
            } else {
                CoroutineContext::forget(self::ENCODER_CONTEXT_KEY);
            }

            if (! $hadMarker) {
                CoroutineContext::forget(self::ENCODER_MARKER_CONTEXT_KEY);
            }
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$encodeUsingFactory = null;
        static::$encodeUsingMarker = null;
    }
}
