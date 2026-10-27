<p align="center"><a href="https://hypervel.org" target="_blank"><img src="https://hypervel.org/logo.png" width="400"></a></p>

<p align="center">
<a href="https://github.com/hypervel/components/actions/workflows/tests.yml?query=branch%3A0.4"><img src="https://github.com/hypervel/components/actions/workflows/tests.yml/badge.svg?branch=0.4" alt="Build Status"></a>
<a href="https://packagist.org/packages/hypervel/framework"><img src="https://img.shields.io/packagist/dt/hypervel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/hypervel/framework"><img src="https://img.shields.io/packagist/v/hypervel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/hypervel/framework"><img src="https://img.shields.io/packagist/l/hypervel/framework" alt="License"></a>
</p>

> [!WARNING]
> This branch contains the ongoing, unreleased work for the Hypervel 0.4 rewrite.
>
> Hypervel 0.4 is not ready for use yet. APIs, behavior, configuration, and package internals may change unexpectedly while the rewrite is still in progress.
>
> If you are experimenting with this branch, use the [in-progress 0.4 documentation](https://github.com/hypervel/components/tree/0.4/src/docs). If you are coming from Laravel, begin with the [porting guide](https://github.com/hypervel/components/blob/0.4/src/docs/porting-from-laravel.md).
>
> Please do not use this branch for projects until a beta release is tagged. If you are experimenting or testing the rewrite, bug reports and feedback are very welcome.

## Introduction

This metapackage installs the standard Hypervel framework components. Framework source code and contributions live in [hypervel/components](https://github.com/hypervel/components).

## Trying Hypervel 0.4

To create an application using the [Hypervel application skeleton](https://github.com/hypervel/hypervel/tree/0.4) and the unreleased 0.4 framework, run:

```bash
composer create-project hypervel/hypervel example-app "0.4.x-dev"
```

The explicit version selects the development branch. Without it, Composer selects a stable release. Run `composer update` inside the application to receive newer development commits.

See the [0.4 installation documentation](https://github.com/hypervel/components/blob/0.4/src/docs/installation.md) for system requirements and setup instructions.
