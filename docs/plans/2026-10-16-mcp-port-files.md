# MCP port remaining files

Use with [the design plan](2026-10-16-mcp-port.md). Paths on the left are relative to the upstream MCP checkout. Copy with cp, then read and adapt each file; preserve applicable upstream tests. Work alphabetically within each implementation slice. Remove completed entries; keep no command logs, review history or completed-work ledger here.

## Existing Hypervel consumers and wiring

- docs/todo.md — resolve completed AI/MCP item; extend existing buffered-reader follow-up

## Repository infrastructure disposition

Upstream repository-level .github automation, .editorconfig, .gitattributes, .gitignore, phpstan.neon.dist, phpunit.xml.dist, pint.json and rector.php are references for the existing monorepo tools, not replacement root files. Translate relevant verification requirements into existing workflows/configuration only where needed. testbench.yaml behavior belongs in the test fixture/bootstrap and isolated dogfood/mcp project; do not replace the shared testbench setup. Omit tests/Conformance/.gitignore because logs/results live outside the source tree. CHANGELOG.md is upstream release history, not a new Hypervel changelog. art/ contains Laravel branding and is not runtime Apps content. resources/boost is globally excluded. No runtime JavaScript/view/stub is covered by those exclusions.

New regression tests and optional benchmark files follow the design plan's owning directories. Add their paths here once their concrete files are chosen; do not invent a separate class for each assertion.

