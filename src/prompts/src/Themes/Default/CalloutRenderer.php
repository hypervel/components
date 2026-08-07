<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

use Hypervel\Prompts\Callout;
use Hypervel\Prompts\Elements\BulletedList;
use Hypervel\Prompts\Elements\ElementContract;
use Hypervel\Prompts\Elements\Heading;
use Hypervel\Prompts\Elements\KeyValueList;
use Hypervel\Prompts\Elements\Link;
use Hypervel\Prompts\Elements\NumberedList;
use InvalidArgumentException;

class CalloutRenderer extends Renderer
{
    use Concerns\DrawsBoxes;

    /**
     * Render the callout.
     */
    public function __invoke(Callout $prompt): string
    {
        $content = is_array($prompt->content) ? $prompt->content : [$prompt->content];

        $sections = [];

        foreach ($content as $part) {
            $result = $this->resolvePart($part);

            if (is_array($result)) {
                $sections[] = implode(PHP_EOL, $result);
            } else {
                $sections[] = implode(PHP_EOL, $this->ansiWordwrap($result, $this->minWidth));
            }
        }

        $message = implode(PHP_EOL . PHP_EOL, $sections);

        return match ($prompt->type) {
            'error' => (string) $this
                ->box(
                    $this->red($this->truncate('⚠ ' . $prompt->label, $prompt->terminal()->cols() - 6)),
                    $message,
                    color: 'red',
                    info: $prompt->info,
                ),

            'warning' => (string) $this
                ->box(
                    $this->yellow($this->truncate('⚠ ' . $prompt->label, $prompt->terminal()->cols() - 6)),
                    $message,
                    color: 'yellow',
                    info: $prompt->info,
                ),

            default => (string) $this
                ->box(
                    $this->cyan($this->truncate($prompt->label, $prompt->terminal()->cols() - 6)),
                    $message,
                    info: $prompt->info,
                ),
        };
    }

    /**
     * Resolve a part of the callout content into a string or array of strings.
     *
     * @return array<int, string>|string
     */
    protected function resolvePart(string|ElementContract $part): string|array
    {
        return match (true) {
            is_string($part) => $this->autoFormat($part),
            $part instanceof Heading => $this->bold($this->autoFormat($part->text)),
            $part instanceof BulletedList => $this->renderBulletedList($part),
            $part instanceof NumberedList => $this->renderNumberedList($part),
            $part instanceof KeyValueList => $this->renderKeyValueList($part),
            $part instanceof Link => $this->renderLink($part),
            default => throw new InvalidArgumentException('Unsupported callout content part: ' . get_debug_type($part)),
        };
    }

    /**
     * Render a bulleted list element.
     *
     * @return array<int, string>
     */
    protected function renderBulletedList(BulletedList $part): array
    {
        $finalLines = [];
        // Array keys do not define a list item's display position.
        $ordinal = 0;

        foreach ($part->items as $p) {
            ++$ordinal;
            $p = $this->autoFormat($p);
            $lines = $this->ansiWordwrap($p, $this->minWidth - 2);
            $partLines = [];

            if ($part->spaced && $ordinal !== 1) {
                $partLines[] = '';
            }

            foreach ($lines as $index => $line) {
                if ($index === 0) {
                    $partLines[] = $this->dim('·') . ' ' . $line;
                } else {
                    $partLines[] = '  ' . $line;
                }
            }

            $finalLines[] = implode(PHP_EOL, $partLines);
        }

        return $finalLines;
    }

    /**
     * Render a numbered list element.
     *
     * @return array<int, string>
     */
    protected function renderNumberedList(NumberedList $part): array
    {
        $finalLines = [];
        // +1 for "."
        $widestNumber = mb_strwidth((string) count($part->items)) + 1;
        // Array keys do not define a list item's display number.
        $ordinal = 0;

        foreach ($part->items as $p) {
            ++$ordinal;
            $partLines = [];
            // -1 for ' ' after number
            $p = $this->autoFormat($p);
            $lines = $this->ansiWordwrap($p, $this->minWidth - $widestNumber - 1);

            if ($part->spaced && $ordinal !== 1) {
                $partLines[] = '';
            }

            foreach ($lines as $index => $line) {
                if ($index === 0) {
                    $partLines[] = $this->dim(mb_str_pad($ordinal . '.', $widestNumber, pad_type: STR_PAD_LEFT)) . ' ' . $line;
                } else {
                    // +1 for ' ' after number
                    $partLines[] = str_repeat(' ', $widestNumber + 1) . $line;
                }
            }

            $finalLines[] = implode(PHP_EOL, $partLines);
        }

        return $finalLines;
    }

    /**
     * Render a key-value list element.
     *
     * @return array<int, string>
     */
    protected function renderKeyValueList(KeyValueList $part): array
    {
        $items = $part->items;

        if ($items === []) {
            return [];
        }

        $keys = array_keys($items);
        $widestKey = max(array_map(fn ($key) => mb_strwidth((string) $key), $keys));

        $finalLines = [];

        foreach ($items as $key => $value) {
            $key = (string) $key;
            $paddedKey = mb_str_pad($key, $widestKey);
            $value = $this->autoFormat($value);
            $lines = $this->ansiWordwrap($value, $this->minWidth - $widestKey - 2);

            foreach ($lines as $index => $line) {
                if ($index === 0) {
                    $finalLines[] = $this->dim($paddedKey) . '  ' . $line;
                } else {
                    $finalLines[] = str_repeat(' ', $widestKey + 2) . $line;
                }
            }
        }

        return $finalLines;
    }

    /**
     * Render a link element.
     */
    protected function renderLink(Link $part): string
    {
        $text = $part->underline
            ? "\e[4;36m{$part->label}\e[0m"
            : $this->cyan($part->label);

        return "\e]8;;{$part->url}\e\\{$text}\e]8;;\e\\";
    }

    /**
     * Auto-format the text by applying styles to specific patterns, such as inline code blocks.
     */
    protected function autoFormat(string $text): string
    {
        $text = preg_replace('/`([^`]+)`/', $this->cyan('`$1`'), $text);

        return preg_replace_callback('/\e\]8;;(.+?)\e\\\(.*?)\e\]8;;\e\\\/', function ($matches) {
            $visibleText = $this->stripEscapeSequences($matches[2]);
            $hadUnderline = str_contains($matches[2], "\e[4m");
            $styled = $hadUnderline
                ? "\e[4;36m{$visibleText}\e[0m"
                : $this->cyan($visibleText);

            return "\e]8;;{$matches[1]}\e\\{$styled}\e]8;;\e\\";
        }, $text);
    }
}
