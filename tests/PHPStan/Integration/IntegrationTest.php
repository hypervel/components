<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Integration;

use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Error;
use PHPStan\File\FileHelper;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function array_map;
use function ksort;
use function sort;
use function sprintf;

class IntegrationTest extends PHPStanTestCase
{
    /**
     * @param null|array<int, array<int, string>> $expectedErrors
     *
     * @throws Throwable
     */
    #[DataProvider('dataIntegrationTests')]
    public function testIntegration(string $file, ?array $expectedErrors = null): void
    {
        $errors = $this->runAnalyse($file);

        if ($expectedErrors === null) {
            $this->assertNoErrors($errors);
        } else {
            $this->assertSameErrorMessages($file, $expectedErrors, $errors);
        }
    }

    /** @return iterable<array{0: string, 1?: array<int, array<int, string>>}> */
    public static function dataIntegrationTests(): iterable
    {
        self::getContainer();

        yield [__DIR__ . '/data/blade-view.php'];

        yield [
            __DIR__ . '/data/view-string-content.php',
            [
                10 => ['Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                11 => ['Parameter $html of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                12 => ['Parameter $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                13 => ['Parameter $markdown of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                14 => [
                    'Parameter #1 $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                    'Parameter #2 $html of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                    'Parameter #3 $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                    'Parameter #4 $markdown of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                ],
                15 => ['Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                28 => [
                    'Parameter $html of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                    'Parameter $markdown of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                    'Parameter $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                    'Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                ],
                29 => ['Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                30 => ['Parameter $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
            ],
        ];

        yield [
            __DIR__ . '/data/view-string-signatures.php',
            [
                17 => ['Parameter #1 $view of method Hypervel\Contracts\View\Factory::make() expects view-string, string given.'],
                23 => ['Parameter #2 $view of method Hypervel\Routing\Router::view() expects view-string, string given.'],
                29 => ['Parameter #1 $view of method Hypervel\Notifications\Messages\MailMessage::view() expects array<view-string>|view-string, string given.'],
                31 => ['Parameter #1 $view of method Hypervel\Notifications\Messages\MailMessage::markdown() expects view-string, string given.'],
                38 => ['Parameter #1 $value of method Hypervel\Testing\TestResponse<Hypervel\Http\Response>::assertViewIs() expects view-string, string given.'],
                52 => ['Parameter #1 $view of method ViewStringSignatures\UsesInteractsWithViews::view() expects view-string, string given.'],
                59 => ['Parameter #1 $view of static method Hypervel\View\Factory::make() expects view-string, string given.'],
                65 => ['Parameter #2 $view of static method Hypervel\Routing\Router::view() expects view-string, string given.'],
                71 => ['Parameter #1 $textView of method Hypervel\Notifications\Messages\MailMessage::text() expects view-string, string given.'],
                77 => ['Parameter #1 $textView of method Hypervel\Mail\Mailable::text() expects view-string, string given.'],
            ],
        ];

        yield [
            __DIR__ . '/../Fixtures/Integration/view-helper.php',
            [
                13 => ['Parameter #1 $view of function view expects view-string|null, string given.'],
            ],
        ];
    }

    /**
     * Analyze the given file and return its errors.
     *
     * @see https://github.com/phpstan/phpstan-src/blob/c9772621c0bd6eab7e02fdaa03714bea239b372d/tests/PHPStan/Analyser/AnalyserIntegrationTest.php#L604-L622
     * @see https://github.com/phpstan/phpstan/discussions/6888#discussioncomment-2423613
     *
     * @param null|string[] $allAnalysedFiles
     *
     * @return Error[]
     *
     * @throws Throwable
     */
    private function runAnalyse(string $file, ?array $allAnalysedFiles = null): array
    {
        $file = $this->getFileHelper()->normalizePath($file);

        /** @var Analyser $analyser */
        $analyser = self::getContainer()->getByType(Analyser::class); // @phpstan-ignore-line

        /** @var FileHelper $fileHelper */
        $fileHelper = self::getContainer()->getByType(FileHelper::class);

        $errors = $analyser->analyse([$file], null, null, true, $allAnalysedFiles)->getErrors(); // @phpstan-ignore-line

        foreach ($errors as $error) {
            $this->assertSame($fileHelper->normalizePath($file), $error->getFilePath());
        }

        return $errors;
    }

    /**
     * Assert that the file reports exactly the expected errors.
     *
     * Upstream only checks that each reported error is expected, so an expected
     * error that is no longer reported would pass.
     *
     * @param array<int, array<int, string>> $expectedErrors
     * @param Error[] $errors
     */
    private function assertSameErrorMessages(string $file, array $expectedErrors, array $errors): void
    {
        $actualErrors = [];

        foreach ($errors as $error) {
            $actualErrors[$error->getLine() ?? 0][] = $error->getMessage();
        }

        $normalize = static function (array $errorsByLine): array {
            ksort($errorsByLine);

            return array_map(static function (array $messages): array {
                sort($messages);

                return $messages;
            }, $errorsByLine);
        };

        $this->assertSame($normalize($expectedErrors), $normalize($actualErrors), sprintf('File %s reports different errors.', $file));
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../phpstan-tests.neon',
        ];
    }
}
