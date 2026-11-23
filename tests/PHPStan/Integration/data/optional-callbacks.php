<?php

namespace OptionalCallbacks;

class Bar
{
    public function name(): string
    {
        return '';
    }
}

/**
 * @return list<int|string|null>
 */
function test(?Bar $maybeBar, ?int $maybeInt): array
{
    return [
        optional($maybeBar, fn (Bar $bar): string => $bar->name()),
        optional($maybeInt, fn (int $value): int => $value + 1),
        optional($maybeBar, fn ($bar) => $bar->name()),
    ];
}
