<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Ui\Enums;

enum Library: string
{
    case Tailwind = 'tailwind';
    case Alpine = 'alpine';

    /**
     * Get the CSP resource domains required by this library.
     *
     * @return array<int, string>
     */
    public function domains(): array
    {
        return match ($this) {
            self::Tailwind => ['https://cdn.tailwindcss.com'],
            self::Alpine => ['https://cdn.jsdelivr.net'],
        };
    }

    /**
     * Get the HTML tags to include in the document head.
     *
     * @return array<int, string>
     */
    public function scriptTags(): array
    {
        return match ($this) {
            self::Tailwind => [
                '<script src="https://cdn.tailwindcss.com"></script>',
                "<script>tailwind.config = { darkMode: ['selector', '[data-theme=\"dark\"]'] }</script>",
            ],
            self::Alpine => [
                '<style>[x-cloak] { display: none !important; }</style>',
                '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>',
            ],
        };
    }
}
