Support for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/support)

## Differences From Laravel

Node package-manager detection walks from the current directory to the filesystem root, so applications inside a workspace use the nearest ancestor lockfile. Within each directory, detection prefers Bun, pnpm, Yarn, then npm.

pnpm and Yarn execute installed package binaries with `pnpm exec` and `yarn run`, keeping development tools tied to the project's installed versions.
