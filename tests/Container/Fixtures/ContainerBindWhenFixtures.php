<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container\Fixtures;

use Hypervel\Container\Attributes\Bind;
use Hypervel\Container\Attributes\BindWhen;
use Hypervel\Container\Attributes\Scoped;
use Hypervel\Container\Attributes\Singleton;
use Hypervel\Contracts\Container\Container as ContainerContract;

#[BindWhen(BindWhenFalseConcrete::class, static function (): bool {
    return false;
})]
#[BindWhen(BindWhenTrueConcrete::class, static function (): bool {
    return true;
})]
interface BindWhenInterface
{
}

class BindWhenFalseConcrete implements BindWhenInterface
{
}

class BindWhenTrueConcrete implements BindWhenInterface
{
}

#[BindWhen(BindWhenSingletonConcrete::class, static function (): bool {
    return true;
})]
#[Singleton]
interface BindWhenSingletonInterface
{
}

class BindWhenSingletonConcrete implements BindWhenSingletonInterface
{
}

#[BindWhen(BindWhenScopedConcrete::class, static function (): bool {
    return true;
})]
#[Scoped]
interface BindWhenScopedInterface
{
}

class BindWhenScopedConcrete implements BindWhenScopedInterface
{
}

#[BindWhen(BindWhenNoMatchConcrete::class, static function (): bool {
    return false;
})]
interface BindWhenNoMatchInterface
{
}

class BindWhenNoMatchConcrete implements BindWhenNoMatchInterface
{
}

#[BindWhen(BindWhenConditionalConcrete::class, static function (ContainerContract $container): bool {
    return $container->bound(BindWhenCondition::class);
})]
interface BindWhenConditionalInterface
{
}

class BindWhenCondition
{
}

class BindWhenConditionalConcrete implements BindWhenConditionalInterface
{
}

#[BindWhen(BindWhenMaterializedConcrete::class, static function (ContainerContract $container): bool {
    return $container->make(BindWhenState::class)->enabled;
})]
interface BindWhenMaterializedInterface
{
}

class BindWhenMaterializedConcrete implements BindWhenMaterializedInterface
{
}

class BindWhenState
{
    public function __construct(public bool $enabled)
    {
    }
}

#[BindWhen(BindWhenWinsConcrete::class, static function (): bool {
    return true;
})]
#[Bind(BindLosesConcrete::class)]
interface BindWhenAndBindInterface
{
}

class BindWhenWinsConcrete implements BindWhenAndBindInterface
{
}

class BindLosesConcrete implements BindWhenAndBindInterface
{
}

#[BindWhen(BindWhenSkippedConcrete::class, static function (): bool {
    return false;
})]
#[Bind(BindFallbackConcrete::class)]
interface BindWhenFallbackInterface
{
}

class BindWhenSkippedConcrete implements BindWhenFallbackInterface
{
}

class BindFallbackConcrete implements BindWhenFallbackInterface
{
}

#[Bind(BindBeforeConcrete::class, environments: 'foobar')]
#[BindWhen(BindWhenAfterConcrete::class, static function (): bool {
    return true;
})]
interface BindBeforeBindWhenInterface
{
}

class BindBeforeConcrete implements BindBeforeBindWhenInterface
{
}

class BindWhenAfterConcrete implements BindBeforeBindWhenInterface
{
}

#[Bind(FirstWildcardConcrete::class)]
#[Bind(SecondWildcardConcrete::class)]
interface MultipleWildcardBindInterface
{
}

class FirstWildcardConcrete implements MultipleWildcardBindInterface
{
}

class SecondWildcardConcrete implements MultipleWildcardBindInterface
{
}
