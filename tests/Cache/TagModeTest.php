<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\TagMode;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class TagModeTest extends TestCase
{
    public function testFromConfigReturnsAnyForAny(): void
    {
        $this->assertSame(TagMode::Any, TagMode::fromConfig('any'));
    }

    public function testFromConfigReturnsAllForAll(): void
    {
        $this->assertSame(TagMode::All, TagMode::fromConfig('all'));
    }

    #[DataProvider('invalidTagModes')]
    public function testFromConfigRejectsInvalidModes(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Invalid cache tag mode [{$mode}]. Supported modes are [any, all]."
        );

        TagMode::fromConfig($mode);
    }

    public static function invalidTagModes(): array
    {
        return [
            'unknown' => ['garbage'],
            'case mismatch' => ['ANY'],
            'empty' => [''],
        ];
    }

    public function testIsAnyModeIsTrueForAnyCase(): void
    {
        $this->assertTrue(TagMode::Any->isAnyMode());
        $this->assertFalse(TagMode::All->isAnyMode());
    }

    public function testIsAllModeIsTrueForAllCase(): void
    {
        $this->assertTrue(TagMode::All->isAllMode());
        $this->assertFalse(TagMode::Any->isAllMode());
    }

    public function testSupportsDirectGetMatchesAnyMode(): void
    {
        $this->assertTrue(TagMode::Any->supportsDirectGet());
        $this->assertFalse(TagMode::All->supportsDirectGet());
    }
}
