<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Translation;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\UploadedFile;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rules\File;
use PHPUnit\Framework\Attributes\DataProvider;

class TranslatorTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['translator']->addNamespace('tests', __DIR__ . '/Fixtures/lang');
        $app['translator']->addJsonPath(__DIR__ . '/Fixtures/lang');
    }

    public function testItCanGetFromLocaleForJson(): void
    {
        $this->assertSame('30 Days', $this->app['translator']->get('30 Days'));

        $this->app->setLocale('fr');

        $this->assertSame('30 jours', $this->app['translator']->get('30 Days'));
    }

    public function testItCanCheckLanguageExistsHasFromLocaleForJson(): void
    {
        $this->assertTrue($this->app['translator']->has('1 Day'));
        $this->assertTrue($this->app['translator']->hasForLocale('1 Day'));
        $this->assertTrue($this->app['translator']->hasForLocale('30 Days'));

        $this->app->setLocale('fr');

        $this->assertFalse($this->app['translator']->has('1 Day'));
        $this->assertFalse($this->app['translator']->hasForLocale('1 Day'));
        $this->assertTrue($this->app['translator']->hasForLocale('30 Days'));
    }

    public function testItCanCheckKeyExistsWithoutTriggeringHandleMissingKeys(): void
    {
        $missingKey = null;

        $this->app['translator']->handleMissingKeysUsing(function (string $key) use (&$missingKey): void {
            $missingKey = $key;
        });

        $this->assertFalse($this->app['translator']->has('Foo Bar'));
        $this->assertNull($missingKey);

        $this->assertFalse($this->app['translator']->hasForLocale('Foo Bar', 'nl'));
        $this->assertNull($missingKey);
    }

    public function testItCanHandleMissingKeysUsingCallback(): void
    {
        $missingKey = null;

        $this->app['translator']->handleMissingKeysUsing(function (string $key) use (&$missingKey): string {
            $missingKey = $key;

            return 'callback key';
        });

        $key = $this->app['translator']->get('some missing key');

        $this->assertSame('callback key', $key);
        $this->assertSame('some missing key', $missingKey);
    }

    public function testItCanHandleMissingKeysNoReturn(): void
    {
        $missingKey = null;

        $this->app['translator']->handleMissingKeysUsing(function (string $key) use (&$missingKey): void {
            $missingKey = $key;
        });

        $key = $this->app['translator']->get('some missing key');

        $this->assertSame('some missing key', $key);
        $this->assertSame('some missing key', $missingKey);
    }

    public function testItReturnsCorrectLocaleForMissingKeys(): void
    {
        $missingLocale = null;

        $this->app['translator']->handleMissingKeysUsing(function (string $key, array $replacements, string $locale) use (&$missingLocale): void {
            $missingLocale = $locale;
        });

        $this->app['translator']->get('some missing key', [], 'ht');

        $this->assertSame('ht', $missingLocale);
    }

    public function testFileValidationDoesNotAttemptToTranslateAlreadyTranslatedMessages(): void
    {
        $keysLookedUp = [];

        $this->app['translator']->handleMissingKeysUsing(function (string $key) use (&$keysLookedUp): void {
            $keysLookedUp[] = $key;
        });

        $validator = $this->app['validator']->make(
            ['file' => UploadedFile::fake()->create('file.pdf')],
            ['file' => [File::types(['txt'])]]
        );

        $validator->fails();

        $this->assertNotContains('The file field must be a file of type: txt.', $keysLookedUp);
    }

    #[DataProvider('greetingChoiceDataProvider')]
    public function testItCanHandleChoice(int $count, string $expected, ?string $locale = null): void
    {
        if ($locale !== null) {
            $this->app->setLocale($locale);
        }

        $name = 'Taylor';

        $this->assertSame(
            strtr($expected, [':name' => $name, ':count' => $count]),
            $this->app['translator']->choice('tests::app.greeting', $count, ['name' => $name])
        );
    }

    #[DataProvider('greetingChoiceDataProvider')]
    public function testItCanHandleChoiceWithChoiceSeparatorInReplaceString(int $count, string $expected, ?string $locale = null): void
    {
        if ($locale !== null) {
            $this->app->setLocale($locale);
        }

        $name = 'Taylor | Hypervel';

        $this->assertSame(
            strtr($expected, [':name' => $name, ':count' => $count]),
            $this->app['translator']->choice('tests::app.greeting', $count, ['name' => $name])
        );
    }

    /**
     * @return array<int, array{int, string, 2?: string}>
     */
    public static function greetingChoiceDataProvider(): array
    {
        return [
            [0, 'Hello :name'],
            [3, 'Hello :name, you have 3 unread messages'],
            [0, 'Bonjour :name', 'fr'],
            [3, 'Bonjour :name, vous avez :count messages non lus', 'fr'],
        ];
    }
}
