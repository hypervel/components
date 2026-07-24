<?php

declare(strict_types=1);

namespace Hypervel\Tests\Translation;

use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;

class TranslationArrayLoaderTest extends TestCase
{
    public function testZeroNamespaceIsPreserved(): void
    {
        $loader = new ArrayLoader;
        $loader->addMessages('en', 'messages', ['value' => 'zero'], '0');

        $this->assertSame(['value' => 'zero'], $loader->load('en', 'messages', '0'));
        $this->assertSame([], $loader->load('en', 'messages'));
    }

    public function testEmptyNamespaceUsesWildcardNamespace(): void
    {
        $loader = new ArrayLoader;
        $loader->addMessages('en', 'messages', ['value' => 'wildcard'], '');

        $this->assertSame(['value' => 'wildcard'], $loader->load('en', 'messages'));
        $this->assertSame(['value' => 'wildcard'], $loader->load('en', 'messages', ''));
    }
}
