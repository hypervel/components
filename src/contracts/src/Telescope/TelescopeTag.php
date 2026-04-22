<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Telescope;

/**
 * Registry of telescope tags emitted by framework packages.
 *
 * Framework code (Scout, Queue, Mail, etc.) uses these cases when tagging
 * outbound HTTP or operation telemetry, providing one canonical vocabulary
 * and a single source of truth that external tooling (MCP servers,
 * dashboards) can enumerate via `TelescopeTag::cases()`.
 *
 * Lives in the contracts package so framework packages can reference it
 * without depending on the optional Telescope package itself. Application
 * code remains free to pass arbitrary strings via the user-facing
 * `withTelescopeTags()` API.
 */
enum TelescopeTag: string
{
    case Scout = 'scout';
    case Algolia = 'algolia';
    case Meilisearch = 'meilisearch';
    case Typesense = 'typesense';

    /**
     * Human-readable description of what the tag denotes.
     *
     * Used by discovery tooling (e.g. a Telescope MCP server) to surface
     * the set of framework-emitted tags with context.
     */
    public function description(): string
    {
        return match ($this) {
            self::Scout => 'Hypervel Scout — full-text search package.',
            self::Algolia => 'Scout Algolia driver.',
            self::Meilisearch => 'Scout Meilisearch driver.',
            self::Typesense => 'Scout Typesense driver.',
        };
    }
}
