<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Mail\Markdown;
use Hypervel\Testbench\TestCase;
use ReflectionProperty;

class MailServiceProviderTest extends TestCase
{
    public function testMarkdownConfigurationMayOmitConstructorOwnedOptions(): void
    {
        config(['mail.markdown' => []]);

        $markdown = $this->app->make(Markdown::class);

        $this->assertSame('default', (new ReflectionProperty(Markdown::class, 'theme'))->getValue($markdown));
        $this->assertSame([], (new ReflectionProperty(Markdown::class, 'componentPaths'))->getValue($markdown));
        $this->assertSame([], (new ReflectionProperty(Markdown::class, 'extensions'))->getValue());
    }
}
