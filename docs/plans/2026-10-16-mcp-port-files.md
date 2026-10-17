# MCP port remaining files

Use with [the design plan](2026-10-16-mcp-port.md). Paths on the left are relative to the upstream MCP checkout. Copy with cp, then read and adapt each file; preserve applicable upstream tests. Work alphabetically within each implementation slice. Remove completed entries; keep no command logs, review history or completed-work ledger here.

## Configuration, routes, stubs and assets

- resources/js/mcp-sdk.js → src/mcp/resources/js/mcp-sdk.js
- resources/js/mcp-sdk.min.js → src/mcp/resources/js/mcp-sdk.min.js
- resources/views/mcp/components/app.blade.php → src/mcp/resources/views/mcp/components/app.blade.php
- routes/ai.php → src/mcp/routes/ai.php

## Source

- src/Client/OAuth/AuthServerDiscovery.php → src/mcp/src/Client/OAuth/AuthServerDiscovery.php
- src/Client/OAuth/AuthServerMetadata.php → src/mcp/src/Client/OAuth/AuthServerMetadata.php
- src/Client/OAuth/ClientRegistration.php → src/mcp/src/Client/OAuth/ClientRegistration.php
- src/Client/OAuth/Concerns/InteractsWithOAuthEndpoints.php → src/mcp/src/Client/OAuth/Concerns/InteractsWithOAuthEndpoints.php
- src/Client/OAuth/DiscoveryResult.php → src/mcp/src/Client/OAuth/DiscoveryResult.php
- src/Client/OAuth/DynamicClientRegistration.php → src/mcp/src/Client/OAuth/DynamicClientRegistration.php
- src/Client/OAuth/Enums/TokenEndpointAuthMethod.php → src/mcp/src/Client/OAuth/Enums/TokenEndpointAuthMethod.php
- src/Client/OAuth/OAuthClient.php → src/mcp/src/Client/OAuth/OAuthClient.php
- src/Client/OAuth/OAuthRouteRegistrar.php → src/mcp/src/Client/OAuth/OAuthRouteRegistrar.php
- src/Client/OAuth/Pkce.php → src/mcp/src/Client/OAuth/Pkce.php
- src/Client/OAuth/TokenSet.php → src/mcp/src/Client/OAuth/TokenSet.php

## Tests and fixtures

- tests/ArchTest.php → tests/Mcp/ArchTest.php — Translate applicable architecture checks
- tests/Feature/Client/OAuthCallbackRouteTest.php → tests/Mcp/Feature/Client/OAuthCallbackRouteTest.php
- tests/Fixtures/Client/OAuthCallbackController.php → tests/Mcp/Fixtures/Client/OAuthCallbackController.php
- tests/Unit/Client/OAuth/OAuthClientTest.php → tests/Mcp/Unit/Client/OAuth/OAuthClientTest.php
- tests/Unit/Client/OAuth/OAuthTransportTest.php → tests/Mcp/Unit/Client/OAuth/OAuthTransportTest.php
- tests/Unit/Server/McpServiceProviderTest.php → tests/Mcp/Unit/Server/McpServiceProviderTest.php

## Conformance harness

- dogfood/mcp/composer.json — isolated package-mode project/path repository and conformance autoloading
- dogfood/mcp/testbench.yaml — opt-in server configuration
- dogfood/mcp/workbench/app/Providers/WorkbenchServiceProvider.php — conformance route only
- dogfood/mcp/README.md — local setup, client/server commands and temporary output location

- tests/Conformance/ConformanceServer.php → dogfood/mcp/conformance/ConformanceServer.php
- tests/Conformance/Fixtures.php → dogfood/mcp/conformance/Fixtures.php
- tests/Conformance/Prompts/PromptWithArguments.php → dogfood/mcp/conformance/Prompts/PromptWithArguments.php
- tests/Conformance/Prompts/PromptWithEmbeddedResource.php → dogfood/mcp/conformance/Prompts/PromptWithEmbeddedResource.php
- tests/Conformance/Prompts/PromptWithImage.php → dogfood/mcp/conformance/Prompts/PromptWithImage.php
- tests/Conformance/Prompts/SimplePrompt.php → dogfood/mcp/conformance/Prompts/SimplePrompt.php
- tests/Conformance/Resources/StaticBinaryResource.php → dogfood/mcp/conformance/Resources/StaticBinaryResource.php
- tests/Conformance/Resources/StaticTextResource.php → dogfood/mcp/conformance/Resources/StaticTextResource.php
- tests/Conformance/Resources/TemplateResource.php → dogfood/mcp/conformance/Resources/TemplateResource.php
- tests/Conformance/Resources/WatchedResource.php → dogfood/mcp/conformance/Resources/WatchedResource.php
- tests/Conformance/Tools/AudioContentTool.php → dogfood/mcp/conformance/Tools/AudioContentTool.php
- tests/Conformance/Tools/EmbeddedResourceTool.php → dogfood/mcp/conformance/Tools/EmbeddedResourceTool.php
- tests/Conformance/Tools/ErrorHandlingTool.php → dogfood/mcp/conformance/Tools/ErrorHandlingTool.php
- tests/Conformance/Tools/ImageContentTool.php → dogfood/mcp/conformance/Tools/ImageContentTool.php
- tests/Conformance/Tools/MultipleContentTypesTool.php → dogfood/mcp/conformance/Tools/MultipleContentTypesTool.php
- tests/Conformance/Tools/SimpleTextTool.php → dogfood/mcp/conformance/Tools/SimpleTextTool.php
- tests/Conformance/client.php → dogfood/mcp/conformance/client.php
- tests/Conformance/conformance-baseline.yml → dogfood/mcp/conformance/conformance-baseline.yml
- tests/Conformance/run.sh → dogfood/mcp/conformance/run.sh
- tests/Conformance/score.php → dogfood/mcp/conformance/score.php

## Package metadata and canonical documentation

- composer.json → src/mcp/composer.json — use Hypervel first-party metadata and actual dependencies
- README.md → src/mcp/README.md — minimal Hypervel package README
- UPGRADE.md → assess current contracts when porting src/docs/mcp.md; do not ship historical upgrade instructions as new-package guidance
- references/laravel/docs/mcp.md → src/docs/mcp.md — canonical page, excluding Boost guidance

## Existing Hypervel consumers and wiring

- src/http/src/Client/Destinations/ResolvedDestination.php — shared real-host pin options; retain strict direct-route isolation
- tests/Http/Client/Destinations/ResolvedDestinationTest.php — shared pin formatting, IPv6/multiple addresses and literal hosts
- src/ai/src/Files/UntrustedUrl.php — reuse shared pins with buffered requests and existing proxy behavior
- tests/Ai/Feature/Files/UntrustedUrlTest.php — retain download/proxy coverage
- src/docs/ai-sdk.md — available MCP integration and links; hostname-pinning runtime requirement for remote attachments
- src/docs/documentation.md — MCP navigation
- src/docs/porting-from-laravel.md — only concrete porter actions
- docs/todo.md — resolve completed AI/MCP item; extend existing buffered-reader follow-up

## Repository infrastructure disposition

Upstream repository-level .github automation, .editorconfig, .gitattributes, .gitignore, phpstan.neon.dist, phpunit.xml.dist, pint.json and rector.php are references for the existing monorepo tools, not replacement root files. Translate relevant verification requirements into existing workflows/configuration only where needed. testbench.yaml behavior belongs in the test fixture/bootstrap and isolated dogfood/mcp project; do not replace the shared testbench setup. Omit tests/Conformance/.gitignore because logs/results live outside the source tree. CHANGELOG.md is upstream release history, not a new Hypervel changelog. art/ contains Laravel branding and is not runtime Apps content. resources/boost is globally excluded. No runtime JavaScript/view/stub is covered by those exclusions.

New regression tests and optional benchmark files follow the design plan's owning directories. Add their paths here once their concrete files are chosen; do not invent a separate class for each assertion.

