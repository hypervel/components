<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\TypeSafe;

use Closure;
use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Get questions for triaging a support request.
 */
function triageQuestions(): array
{
    return [
        'is_urgent' => new Boolean('Does this message convey urgency?'),
        'department' => new Choice('Which team should handle this?', [
            'billing' => 'Payments, invoicing, refunds',
            'technical' => 'Bugs, outages, integrations',
            'sales' => null,
        ]),
        'frustration' => new Score('How frustrated is the customer?', [
            'Calm, stating facts', 'Frustrated but civil', 'Very angry',
        ]),
    ];
}

/**
 * Create a TypeSafe classification response.
 */
function fakeTypeSafeResponse(): array
{
    return [
        'model' => 'jev-1.13.0',
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.92],
            'department' => [
                'type' => 'choice',
                'choice' => 'technical',
                'probabilities' => ['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07],
                'confidence' => 0.82,
            ],
            'frustration' => [
                'type' => 'score',
                'score' => 1.6,
                'legend' => ['0' => 'Calm, stating facts', '1' => 'Frustrated but civil', '2' => 'Very angry'],
                'probabilities' => ['2' => 0.65, '0' => 0.05, '1' => 0.3],
                'confidence' => 0.78,
            ],
        ],
        'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
    ];
}

class ClassificationTest extends TestCase
{
    /**
     * Configure the TypeSafe credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.typesafe.key', 'test-key');
    }

    public function testClassificationRequestMapsQuestionsToTheSystemOneWireFormat(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
            ->questions(triageQuestions())
            ->classify(provider: 'typesafe', model: 'jev-latest');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.typesafe.ai/v1/systemone'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $body['model'] === 'jev-latest'
                && $body['state'] === 'Stripe connect keeps failing, losing sales, help ASAP'
                && $body['questions'] === [
                    'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this message convey urgency?'],
                    'department' => [
                        'type' => 'choice',
                        'instructions' => 'Which team should handle this?',
                        'criteria' => ['billing' => 'Payments, invoicing, refunds', 'technical' => 'Bugs, outages, integrations', 'sales' => null],
                    ],
                    'frustration' => [
                        'type' => 'score',
                        'instructions' => 'How frustrated is the customer?',
                        'criteria' => ['Calm, stating facts', 'Frustrated but civil', 'Very angry'],
                    ],
                ];
        });
    }

    public function testBooleanCriteriaAreSentWhenGiven(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of('Build finished')
            ->question('passed', new Boolean('Did the build succeed?', [
                'true' => 'exit code 0',
                'false' => 'any non-zero exit code',
            ]))
            ->classify(provider: 'typesafe');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['questions']['passed'] === [
            'type' => 'noul',
            'instructions' => 'Did the build succeed?',
            'criteria' => ['true' => 'exit code 0', 'false' => 'any non-zero exit code'],
        ]);
    }

    public function testNumericChoiceNamesRemainJsonObjectMembers(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of('Invoice question')
            ->question('department', new Choice('Which department?', ['0' => 'Billing', '1' => 'Technical']))
            ->classify(provider: 'typesafe');

        Http::assertSent(function (Request $request): bool {
            $criteria = json_decode($request->body())->questions->department->criteria;

            return is_object($criteria) && (array) $criteria === ['0' => 'Billing', '1' => 'Technical'];
        });
    }

    public function testStructuredStateIsSentAsAnObject(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of(['subject' => 'Help', 'messages' => ['first', 'second']])
            ->question('is_urgent', new Boolean('Urgent?'))
            ->classify(provider: 'typesafe');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['state'] === ['subject' => 'Help', 'messages' => ['first', 'second']]);
    }

    public function testClassificationResponseIsParsedIntoTypedAnswers(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        $response = Classification::of('text')->questions(triageQuestions())->classify(provider: 'typesafe', model: 'jev-latest');

        $this->assertCount(3, $response);
        $this->assertInstanceOf(BooleanAnswer::class, $response['is_urgent']);
        $this->assertSame(0.92, $response['is_urgent']->probability);
        $this->assertTrue($response['is_urgent']->isTrue());
        $this->assertFalse($response['is_urgent']->isTrue(0.95));
        $this->assertInstanceOf(ChoiceAnswer::class, $response['department']);
        $this->assertSame('technical', $response['department']->choice);
        $this->assertSame(0.08, $response['department']->probabilityOf('billing'));
        $this->assertSame(0.82, $response['department']->confidence);
        $this->assertInstanceOf(ScoreAnswer::class, $response['frustration']);
        $this->assertSame(1.6, $response['frustration']->score);
        $this->assertSame(2, $response['frustration']->level());
        $this->assertSame([0 => 0.05, 1 => 0.3, 2 => 0.65], $response['frustration']->probabilities);
        $this->assertSame('Very angry', $response['frustration']->label());
        $this->assertSame(0.8, $response['frustration']->normalized());
        $this->assertSame(312, $response->usage->inputTokens);
        $this->assertSame(48, $response->usage->outputTokens);
        $this->assertSame('typesafe', $response->meta->provider);
        $this->assertSame('jev-1.13.0', $response->meta->model);
    }

    public function testUnknownAnswerTypesAreSkipped(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'jev-latest',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.5],
                'future' => ['type' => 'ranking', 'ranking' => []],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

        $this->assertSame(['is_urgent'], $response->collect()->keys()->all());
    }

    public function testClassificationUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'jev-latest');
    }

    public function testClassificationRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.typesafe' => [...config('ai.providers.typesafe'), 'url' => 'http://localhost:8080/v1/']]);

        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1/systemone');
    }

    public function testTheRoutedCheckpointIsReportedAsTheAnsweringModel(): void
    {
        Http::fake(['*' => Http::response([
            ...fakeTypeSafeResponse(),
            'model' => 'laya-rl-agent',
            'routing' => ['model' => 'english', 'reason' => 'English Latin text'],
        ])]);

        $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

        $this->assertSame('english', $response->meta->model);
    }

    public function testProviderOptionsMayNotOverrideTheCoreClassificationPayload(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of('text')
            ->question('is_urgent', new Boolean('Urgent?'))
            ->withProviderOptions(['model' => 'hijacked', 'state' => 'hijacked', 'beam_width' => 4])
            ->classify(provider: 'typesafe', model: 'jev-latest');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'jev-latest' && $body['state'] === 'text' && $body['beam_width'] === 4;
        });
    }

    public function testClassificationThrowsWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['detail' => ['error_type' => 'authentication_error']], 401)]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');
    }

    public function testClassificationRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'rate limit exceeded'], 429)]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');
    }

    #[DataProvider('overloadStatuses')]
    public function testClassificationOverloadedResponseThrowsProviderOverloadedException(int $status): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'overloaded'], $status)]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');
    }

    /**
     * Provide overload response statuses.
     */
    public static function overloadStatuses(): array
    {
        return [[529], [503]];
    }

    public function testImageAttachmentsAreSentAsReferencedAssetsWithTheImagesHeader(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        $jpeg = file_get_contents(__DIR__ . '/../../../Fixtures/Images/blue.jpg');
        $png = file_get_contents(__DIR__ . '/../../../Fixtures/Images/red.png');

        Classification::of('Inspect this.', [
            Image::fromBase64(base64_encode('base64-bytes'), 'image/jpeg')->withProviderOptions(['custom' => 'value']),
            Image::fromPath(__DIR__ . '/../../../Fixtures/Images/blue.jpg'),
            UploadedFile::fake()->createWithContent('upload.png', 'upload-bytes')->mimeType('image/png'),
            Image::fromBase64(base64_encode($png)),
        ])
            ->question('visible_damage', new Boolean('Is the product in the photos damaged?'))
            ->classify(provider: 'typesafe');

        Http::assertSent(function (Request $request) use ($jpeg, $png): bool {
            $body = json_decode($request->body(), true);

            return $request->hasHeader('TypeSafe-Danger-Zone-Images', 'true')
                && $body['state'] === ['Inspect this.', ...array_keys($body['assets'])]
                && array_values($body['assets']) === [
                    ['custom' => 'value', 'type' => 'image', 'format' => 'jpg', 'data_base64' => base64_encode('base64-bytes')],
                    ['type' => 'image', 'format' => 'jpg', 'data_base64' => base64_encode($jpeg)],
                    ['type' => 'image', 'format' => 'png', 'data_base64' => base64_encode('upload-bytes')],
                    ['type' => 'image', 'format' => 'png', 'data_base64' => base64_encode($png)],
                ];
        });
    }

    public function testRemoteAndStoredImagesAreDownloadedAndLabeledInTheStateByAttachmentName(): void
    {
        Http::fake([
            'example.com/*' => Http::response('remote-bytes', 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response(fakeTypeSafeResponse()),
        ]);

        Storage::fake('photos')->put('product.png', 'stored-bytes');

        Classification::of('Inspect this.', [
            'front' => Image::fromUrl('https://example.com/product.jpg'),
            'back' => Image::fromStorage('product.png', 'photos'),
        ])
            ->question('visible_damage', new Boolean('Is the product in `front` or `back` damaged?'))
            ->classify(provider: 'typesafe');

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.typesafe.ai/v1/systemone') {
                return false;
            }

            $body = json_decode($request->body(), true);

            [$front, $back] = array_keys($body['assets']);

            return $body['assets'][$front] === ['type' => 'image', 'format' => 'jpg', 'data_base64' => base64_encode('remote-bytes')]
                && $body['assets'][$back] === ['type' => 'image', 'format' => 'png', 'data_base64' => base64_encode('stored-bytes')]
                && $body['state'] === ['Inspect this.', "front: {$front}", "back: {$back}"];
        });
    }

    #[DataProvider('imageReferenceStates')]
    public function testImageReferencesFollowTheShapeOfTheState(string|array $state, array $attachments, Closure $expected): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of($state, $attachments)
            ->question('visible_damage', new Boolean('Damaged?'))
            ->classify(provider: 'typesafe');

        Http::assertSent(function (Request $request) use ($expected): bool {
            $body = json_decode($request->body(), true);

            return $body['state'] === $expected(...array_keys($body['assets']));
        });
    }

    /**
     * Provide states with attachments and the state expected for the generated image references.
     */
    public static function imageReferenceStates(): array
    {
        return [
            'unnamed images under images in a structured state' => [
                ['subject' => 'Broken lamp'],
                [Image::fromBase64(base64_encode('a'), 'image/png')],
                fn (string $a): array => ['subject' => 'Broken lamp', 'images' => [$a]],
            ],
            'named and unnamed images in a structured state' => [
                ['subject' => 'Broken lamp'],
                ['front' => Image::fromBase64(base64_encode('a'), 'image/png'), Image::fromBase64(base64_encode('b'), 'image/png')],
                fn (string $a, string $b): array => ['subject' => 'Broken lamp', 'front' => $a, 'images' => [$b]],
            ],
            'named and unnamed images in a list state' => [
                ['first', 'second'],
                ['front' => Image::fromBase64(base64_encode('a'), 'image/png'), 3 => Image::fromBase64(base64_encode('b'), 'image/png')],
                fn (string $a, string $b): array => ['first', 'second', "front: {$a}", $b],
            ],
            'an image named images in a structured state' => [
                ['subject' => 'Broken lamp'],
                ['images' => Image::fromBase64(base64_encode('a'), 'image/png')],
                fn (string $a): array => ['subject' => 'Broken lamp', 'images' => $a],
            ],
            'an image named images alongside unnamed images in a list state' => [
                ['first', 'second'],
                ['images' => Image::fromBase64(base64_encode('a'), 'image/png'), Image::fromBase64(base64_encode('b'), 'image/png')],
                fn (string $a, string $b): array => ['first', 'second', "images: {$a}", $b],
            ],
        ];
    }

    public function testRequestsWithoutAttachmentsDoNotSendAssetsOrTheImagesHeader(): void
    {
        Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('TypeSafe-Danger-Zone-Images')
            && ! array_key_exists('assets', json_decode($request->body(), true)));
    }

    #[DataProvider('conflictingImageKeys')]
    public function testImagesThatNeedAKeyTheStructuredStateAlreadyUsesAreRejectedBeforeAnyRequest(array $state, array $attachments, string $message): void
    {
        Http::fake();

        $exception = null;

        try {
            Classification::of($state, $attachments)
                ->question('damaged', new Boolean('Damaged?'))
                ->classify(provider: 'typesafe');
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertSame($message, $exception->getMessage());

        Http::assertNothingSent();
    }

    /**
     * Provide structured states and attachments that need the same key for different values.
     */
    public static function conflictingImageKeys(): array
    {
        return [
            'named' => [
                ['photo' => 'caption'],
                ['photo' => Image::fromBase64(base64_encode('photo'), 'image/png')],
                'TypeSafe adds images to a structured state under their attachment names, or [images] when unnamed; [photo] already in the state.',
            ],
            'unnamed' => [
                ['images' => 'none attached'],
                [Image::fromBase64(base64_encode('photo'), 'image/png')],
                'TypeSafe adds images to a structured state under their attachment names, or [images] when unnamed; [images] already in the state.',
            ],
            'an image named images alongside unnamed images' => [
                ['subject' => 'Compare photos'],
                ['images' => Image::fromBase64(base64_encode('a'), 'image/png'), Image::fromBase64(base64_encode('b'), 'image/png')],
                'TypeSafe adds unnamed images to a structured state under [images], so no attachment may be named [images] alongside them.',
            ],
        ];
    }

    #[DataProvider('unsupportedAttachments')]
    public function testAttachmentsThatAreNotSupportedImagesAreRejectedBeforeAnyRequest(Closure $attachment, string $message): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $exception = null;

        try {
            Classification::of('Inspect this.', [$attachment()])
                ->question('visible_damage', new Boolean('Damaged?'))
                ->classify(provider: 'typesafe');
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString($message, $exception->getMessage());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.typesafe.ai'));
    }

    /**
     * Provide attachments the TypeSafe decisions endpoint does not accept.
     */
    public static function unsupportedAttachments(): array
    {
        return [
            'provider image' => [fn () => Image::fromId('file_123'), 'TypeSafe decisions only accept images with inline content; [Hypervel\Ai\Files\ProviderImage] given.'],
            'pdf upload' => [fn () => UploadedFile::fake()->createWithContent('invoice.pdf', 'pdf-bytes')->mimeType('application/pdf'), '[application/pdf] given.'],
            'webp upload' => [fn () => UploadedFile::fake()->createWithContent('photo.webp', 'webp-bytes')->mimeType('image/webp'), 'TypeSafe decisions only accept JPEG or PNG images; [image/webp] given.'],
            'gif file' => [fn () => Image::fromPath(__DIR__ . '/../../../Fixtures/Images/red.png', 'image/gif'), '[image/gif] given.'],
            'html served as a remote image' => [fn () => Image::fromUrl('https://example.com/product.png'), '[text/html] given.'],
        ];
    }
}
