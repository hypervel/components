<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Support\Collection;

class DataTablePrompt extends Prompt
{
    use Concerns\Scrolling;
    use Concerns\TypedValue;

    /**
     * The table headers.
     *
     * @var array<int, array<int, string>|string>
     */
    public array $headers;

    /**
     * The table rows.
     *
     * @var array<int|string, array<int, string>>
     */
    public array $rows;

    /**
     * Search-invariant natural column metrics.
     *
     * @var null|array{columns: int, widths: list<int>}
     */
    protected ?array $naturalColumnMetrics = null;

    /**
     * The cached filtered rows.
     *
     * @var null|array<int|string, array<int, string>>
     */
    protected ?array $filteredCache = null;

    /**
     * The previous search query (for cache invalidation).
     */
    protected string $previousQuery = '';

    /**
     * Create a new DataTable instance.
     *
     * @param array<int, array<int, string>|string>|Collection<int, array<int, string>|string> $headers
     * @param null|array<int|string, array<int, string>>|Collection<int|string, array<int, string>> $rows
     *
     * @phpstan-param ($rows is null ? list<list<string>>|Collection<int, list<string>> : list<string|list<string>>|Collection<int, string|list<string>>) $headers
     */
    public function __construct(
        array|Collection $headers = [],
        array|Collection|null $rows = null,
        public int $scroll = 10,
        public string $label = '',
        public string $hint = '',
        public bool|string $required = false,
        public mixed $validate = null,
        public ?Closure $transform = null,
        public ?Closure $filter = null,
    ) {
        if ($rows === null) {
            $rows = $headers;
            $headers = [];
        }

        $this->headers = $headers instanceof Collection ? $headers->all() : $headers;
        $this->rows = $rows instanceof Collection ? $rows->all() : $rows;

        $this->initializeScrolling(0);

        $this->trackTypedValue(
            submit: false,
            ignore: fn ($key) => $this->state !== 'search',
        );

        $this->on('key', fn ($key) => match ($this->state) {
            'search' => $this->handleSearchKey($key),
            default => $this->handleBrowseKey($key),
        });
    }

    /**
     * Handle key presses in browse mode.
     */
    protected function handleBrowseKey(string $key): void
    {
        $total = count($this->filteredRows());

        match ($key) {
            Key::UP, Key::UP_ARROW, Key::CTRL_P => $this->highlightPrevious($total),
            Key::DOWN, Key::DOWN_ARROW, Key::CTRL_N => $this->highlightNext($total),
            Key::PAGE_UP => $this->highlight(max(0, $this->highlighted - $this->scroll)),
            Key::PAGE_DOWN => $this->highlight(min($total - 1, $this->highlighted + $this->scroll)),
            Key::oneOf([Key::HOME, Key::CTRL_A], $key) => $this->highlight(0),
            Key::oneOf([Key::END, Key::CTRL_E], $key) => $this->highlight(max(0, $total - 1)),
            Key::ENTER => $total > 0 ? $this->submit() : null,
            '/' => $this->enterSearch(),
            default => null,
        };
    }

    /**
     * Handle key presses in search mode.
     */
    protected function handleSearchKey(string $key): void
    {
        match ($key) {
            Key::ENTER => $this->exitSearch(),
            Key::ESCAPE => $this->cancelSearch(),
            default => $this->search(),
        };
    }

    /**
     * Enter search mode.
     */
    protected function enterSearch(): void
    {
        $this->state = 'search';
        $this->typedValue = '';
        $this->cursorPosition = 0;
    }

    /**
     * Exit search mode, keeping the filtered results.
     */
    protected function exitSearch(): void
    {
        $this->state = 'active';
        $this->highlighted = 0;
        $this->firstVisible = 0;
    }

    /**
     * Cancel search, clearing the query and showing all rows.
     */
    protected function cancelSearch(): void
    {
        $this->state = 'active';
        $this->typedValue = '';
        $this->cursorPosition = 0;
        $this->filteredCache = null;
        $this->previousQuery = '';
        $this->highlighted = 0;
        $this->firstVisible = 0;
    }

    /**
     * Handle typing in search mode.
     */
    protected function search(): void
    {
        $this->filteredCache = null;
        $this->highlighted = 0;
        $this->firstVisible = 0;
    }

    /**
     * Get the search-invariant natural column metrics.
     *
     * @internal
     * @return array{columns: int, widths: list<int>}
     */
    public function naturalColumnMetrics(): array
    {
        if ($this->naturalColumnMetrics !== null) {
            return $this->naturalColumnMetrics;
        }

        $rowColumns = $this->rows === [] ? 0 : max(array_map(count(...), $this->rows));
        $columns = max(count($this->headers), $rowColumns);

        if ($columns === 0) {
            return $this->naturalColumnMetrics = ['columns' => 0, 'widths' => []];
        }

        $headers = array_values($this->headers);
        $headerWidths = array_fill(0, $columns, 0);

        foreach ($headers as $index => $header) {
            $text = is_array($header) ? implode(' ', $header) : $header;
            $headerWidths[$index] = mb_strwidth(Utils::stripEscapeSequences($text));
        }

        $columnWidths = array_fill(0, $columns, []);

        foreach ($this->rows as $row) {
            foreach (array_values($row) as $index => $cell) {
                $cellWidth = 0;

                foreach (preg_split('/\r\n|\n/', $cell) as $line) {
                    $cellWidth = max($cellWidth, mb_strwidth(Utils::stripEscapeSequences($line)));
                }

                if ($cellWidth > 0) {
                    $columnWidths[$index][] = $cellWidth;
                }
            }
        }

        $naturalWidths = array_fill(0, $columns, 0);

        foreach ($columnWidths as $index => $widths) {
            if ($widths === []) {
                $naturalWidths[$index] = $headerWidths[$index];

                continue;
            }

            sort($widths, SORT_NUMERIC);
            $percentileIndex = max(0, (int) ceil(count($widths) * 0.90) - 1);
            $percentile = $widths[$percentileIndex];
            $maximum = $widths[array_key_last($widths)];

            // Use P90 only when the maximum is a clear outlier.
            $naturalWidths[$index] = max(
                $headerWidths[$index],
                $maximum <= $percentile * 2 ? $maximum : $percentile,
            );
        }

        return $this->naturalColumnMetrics = [
            'columns' => $columns,
            'widths' => $naturalWidths,
        ];
    }

    /**
     * Get the filtered rows based on the current search query.
     *
     * @return array<int|string, array<int, string>>
     */
    public function filteredRows(): array
    {
        if ($this->filteredCache !== null && $this->previousQuery === $this->typedValue) {
            return $this->filteredCache;
        }

        $this->previousQuery = $this->typedValue;

        if ($this->typedValue === '') {
            return $this->filteredCache = $this->rows;
        }

        if ($this->filter !== null) {
            return $this->filteredCache = array_filter(
                $this->rows,
                fn ($row) => ($this->filter)($row, $this->typedValue),
            );
        }

        return $this->filteredCache = array_filter(
            $this->rows,
            fn ($row) => str_contains(
                mb_strtolower(implode(' ', $row)),
                mb_strtolower($this->typedValue),
            ),
        );
    }

    /**
     * The currently visible rows.
     *
     * @return array<int|string, array<int, string>>
     */
    public function visible(): array
    {
        return array_slice($this->filteredRows(), $this->firstVisible, $this->scroll, preserve_keys: true);
    }

    /**
     * Get the current search query.
     */
    public function searchValue(): string
    {
        return $this->typedValue;
    }

    /**
     * Get the search query with a virtual cursor.
     */
    public function searchWithCursor(int $maxWidth): string
    {
        if ($this->typedValue === '') {
            return $this->dim($this->addCursor('', 0, $maxWidth));
        }

        return $this->addCursor($this->typedValue, $this->cursorPosition, $maxWidth);
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): mixed
    {
        if ($this->highlighted === null) {
            return null;
        }

        $filtered = $this->filteredRows();
        $keys = array_keys($filtered);

        if (! isset($keys[$this->highlighted])) {
            return null;
        }

        return $keys[$this->highlighted];
    }

    /**
     * Get the selected row for display purposes.
     *
     * @return null|array<int, string>
     */
    public function selectedRow(): ?array
    {
        if ($this->highlighted === null) {
            return null;
        }

        $filtered = $this->filteredRows();
        $keys = array_keys($filtered);

        if (! isset($keys[$this->highlighted])) {
            return null;
        }

        return $filtered[$keys[$this->highlighted]];
    }
}
