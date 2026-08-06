<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Mail\MailServiceProvider;
use Hypervel\Support\Facades\Mail;
use Hypervel\Tests\TestCase;
use JsonException;
use ReflectionClass;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Mail dependencies and discovery metadata are declared consistently.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/mail/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('^8.1', $composer['require']['symfony/http-foundation']);
        $this->assertSame('^8.1', $composer['require']['symfony/mime']);
        $this->assertArrayNotHasKey('hypervel/notifications', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/testing', $composer['require']);

        foreach (['hypervel/filesystem', 'hypervel/http', 'hypervel/testing', 'phpunit/phpunit'] as $package) {
            $this->assertArrayHasKey($package, $composer['suggest']);
            $this->assertIsString($composer['suggest'][$package]);
            $this->assertNotSame('', trim($composer['suggest'][$package]));
        }

        $providers = [MailServiceProvider::class];

        $this->assertSame($providers, $composer['extra']['hypervel']['providers']);
        $this->assertContains(MailServiceProvider::class, $rootComposer['extra']['hypervel']['providers']);
    }

    public function testFacadeDocumentsConcreteMailerSurface(): void
    {
        $docblock = (new ReflectionClass(Mail::class))->getDocComment();
        $this->assertIsString($docblock);

        foreach ([
            'alwaysFrom',
            'alwaysTo',
            'html',
            'plain',
            'render',
            'onQueue',
            'later',
            'getSymfonyTransport',
            'setQueue',
            'macro',
        ] as $method) {
            $this->assertStringContainsString(" {$method}(", $docblock);
        }
    }
}
