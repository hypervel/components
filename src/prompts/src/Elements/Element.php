<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Elements;

class Element
{
    /**
     * Create a heading element.
     */
    public static function heading(string $text): Heading
    {
        return new Heading($text);
    }

    /**
     * Create a bulleted list element.
     *
     * @param array<int, string> $items
     */
    public static function bulletedList(array $items, bool $spaced = false): BulletedList
    {
        return new BulletedList($items, $spaced);
    }

    /**
     * Create a numbered list element.
     *
     * @param array<int, string> $items
     */
    public static function numberedList(array $items, bool $spaced = false): NumberedList
    {
        return new NumberedList($items, $spaced);
    }

    /**
     * Create a key-value list element.
     *
     * @param array<int|string, string> $items
     */
    public static function keyValueList(array $items): KeyValueList
    {
        return new KeyValueList($items);
    }

    /**
     * Create a link element.
     */
    public static function link(string $url, ?string $label = null, bool $underline = true): Link
    {
        return new Link($url, $label, $underline);
    }
}
