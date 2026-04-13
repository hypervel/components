<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class TranslationTest extends TestCase
{
    #[Test]
    public function itCanResolveDefaultLanguagePath(): void
    {
        $this->assertSame(base_path('lang'), $this->app->langPath());
    }

    #[Test]
    public function itCanResolveValidationLanguageString(): void
    {
        $this->assertSame('The name field is required.', __('validation.required', ['attribute' => 'name']));
    }
}
