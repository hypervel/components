<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Support\DeferringDisplayableValue;
use Hypervel\Contracts\Support\Htmlable;
use Override;

class EncodedHtmlString extends HtmlString
{
    /**
     * Context key for temporarily scoped encoder callbacks.
     */
    protected const ENCODER_CONTEXT_KEY = '__support.encoded_html.encoder';

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

        $factory = CoroutineContext::get(self::ENCODER_CONTEXT_KEY) ?? static::$encodeUsingFactory;

        return ($factory ?? function ($value, $doubleEncode) {
            return static::convert($value, doubleEncode: $doubleEncode);
        })($value, $this->doubleEncode);
    }

    /**
     * Set the callable that will be used to encode the HTML strings.
     *
     * Boot-only. The factory persists in a static property for the worker
     * lifetime and applies to every encoded HTML string.
     */
    public static function encodeUsing(?callable $factory = null): void
    {
        static::$encodeUsingFactory = $factory;
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

        CoroutineContext::set(self::ENCODER_CONTEXT_KEY, $factory);

        try {
            return $callback();
        } finally {
            if ($hadPreviousFactory) {
                CoroutineContext::set(self::ENCODER_CONTEXT_KEY, $previousFactory);
            } else {
                CoroutineContext::forget(self::ENCODER_CONTEXT_KEY);
            }
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$encodeUsingFactory = null;
    }
}
