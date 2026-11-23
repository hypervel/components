<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use JsonException;

class ClassificationTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.gemini.key', 'test-key');
    }

    public function testClassificationPostsToTheGeminiDecisionsEndpoint(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
            ->questions([
                'is_urgent' => new Boolean('Does this message convey urgency?', [
                    'true' => 'The customer is blocked.',
                    'false' => ['note' => 'Anything else.'],
                ]),
                'department' => new Choice('Which team should own this ticket?', [
                    'account' => 'Login, permissions, or profile issues.',
                    'frontend' => ['scope' => 'Rendering'],
                    'payments' => null,
                ]),
                'urgency' => new Score('How urgent is this ticket?', [
                    'Next release', 'This week', ['label' => 'Blocking revenue'],
                ]),
            ])
            ->classify(provider: 'gemini', model: 'gemini-decision-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/decisions'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && $body['model'] === 'gemini-decision-1'
                && $body['input'] === 'Stripe connect keeps failing, losing sales, help ASAP'
                && ! array_key_exists('state', $body)
                && $body['questions'] === [
                    'is_urgent' => [
                        'type' => 'noul',
                        'instructions' => 'Does this message convey urgency?',
                        'criteria' => ['true_description' => 'The customer is blocked.', 'false_description' => '{"note":"Anything else."}'],
                    ],
                    'department' => [
                        'type' => 'choice',
                        'instructions' => 'Which team should own this ticket?',
                        'criteria' => ['account' => 'Login, permissions, or profile issues.', 'frontend' => '{"scope":"Rendering"}', 'payments' => null],
                    ],
                    'urgency' => [
                        'type' => 'score',
                        'instructions' => 'How urgent is this ticket?',
                        'criteria' => ['Next release', 'This week', '{"label":"Blocking revenue"}'],
                    ],
                ];
        });
    }

    public function testBooleanQuestionsWithoutCriteriaOrWithOneCaseSendOnlyWhatWasGiven(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of('text')
            ->questions([
                'plain' => new Boolean('Urgent?'),
                'one_case' => new Boolean('Urgent?', ['true' => 'Blocked.']),
            ])
            ->classify(provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['questions'] === [
            'plain' => ['type' => 'noul', 'instructions' => 'Urgent?'],
            'one_case' => ['type' => 'noul', 'instructions' => 'Urgent?', 'criteria' => ['true_description' => 'Blocked.']],
        ]);
    }

    public function testNumericNamesRemainJsonObjectMembersAndEmptyCriteriaAreOmitted(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of('Invoice question')
            ->question('0', new Choice('Which department?', ['0' => 'Billing', '1' => 'Technical']))
            ->question('1', new Boolean('Urgent?', []))
            ->classify(provider: 'gemini');

        Http::assertSent(function (Request $request): bool {
            $questions = json_decode($request->body())->questions;

            return is_object($questions)
                && is_object($questions->{'0'}->criteria)
                && (array) $questions->{'0'}->criteria === ['0' => 'Billing', '1' => 'Technical']
                && (array) $questions->{'1'} === ['type' => 'noul', 'instructions' => 'Urgent?'];
        });
    }

    public function testStructuredStateThatCannotBeEncodedThrowsAJsonException(): void
    {
        Http::fake();

        $this->expectException(JsonException::class);

        Classification::of(['body' => "\xB1"])
            ->question('is_urgent', new Boolean('Urgent?'))
            ->classify(provider: 'gemini');
    }

    public function testStructuredStateIsSentAsJsonText(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of(['subject' => 'Broken lamp', 'body' => 'It arrived cracked.'])
            ->question('is_urgent', new Boolean(['rule' => 'Urgent?']))
            ->classify(provider: 'gemini');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['input'] === '{"subject":"Broken lamp","body":"It arrived cracked."}'
                && $body['questions']['is_urgent']['instructions'] === '{"rule":"Urgent?"}';
        });
    }

    public function testDecisionsResponseIsParsedIntoTypedAnswers(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        $response = Classification::of('text')
            ->questions([
                'is_urgent' => new Boolean('Urgent?'),
                'department' => new Choice('Team?', ['account' => null, 'frontend' => null, 'payments' => null]),
                'urgency' => new Score('How urgent?', ['Next release', 'This week', ['label' => 'Blocking revenue']]),
            ])
            ->classify(provider: 'gemini');

        $this->assertCount(3, $response);
        $this->assertInstanceOf(BooleanAnswer::class, $response['is_urgent']);
        $this->assertSame(0.96, $response['is_urgent']->probability);
        $this->assertInstanceOf(ChoiceAnswer::class, $response['department']);
        $this->assertSame('payments', $response['department']->choice);
        $this->assertSame(0.16, $response['department']->probabilityOf('frontend'));
        $this->assertSame(0.84, $response['department']->confidence);
        $this->assertInstanceOf(ScoreAnswer::class, $response['urgency']);
        $this->assertSame(1.99, $response['urgency']->score);
        $this->assertSame(2, $response['urgency']->level());
        $this->assertSame(['label' => 'Blocking revenue'], $response['urgency']->label());
        $this->assertSame(312, $response->usage->inputTokens);
        $this->assertSame(256, $response->usage->cacheReadInputTokens);
        $this->assertSame(3, $response->usage->outputTokens);
        $this->assertSame('gemini', $response->meta->provider);
        $this->assertSame('gemini-decision-1', $response->meta->model);
    }

    public function testAnswersOfUnknownTypesAreSkipped(): void
    {
        Http::fake(['*' => Http::response([
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.2],
                'future' => ['type' => 'ranking', 'ranking' => ['a', 'b']],
            ],
            'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 1],
        ])]);

        $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

        $this->assertCount(1, $response);
        $this->assertSame(0.2, $response['is_urgent']->probability);
    }

    public function testTheRawDecisionsResponseIsExposedOnTheClassificationResponse(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

        $this->assertSame(315, $response->raw->json('usage.total_tokens'));
    }

    public function testClassificationUsesTheDefaultDecisionsModelWhenNoneIsSpecified(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gemini-decision-1');
    }

    public function testTheDefaultDecisionsModelCanBeConfigured(): void
    {
        config(['ai.providers.gemini.models.classification.default' => 'gemini-decision-2']);

        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gemini-decision-2');
    }

    public function testDecisionsRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.gemini' => [...config('ai.providers.gemini'), 'url' => 'http://localhost:8080/v1beta/']]);

        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1beta/decisions');
    }

    public function testImageAttachmentsAreSentAsInlineImageBlocksLabelledByName(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        $jpeg = file_get_contents(__DIR__ . '/../../../Fixtures/Images/blue.jpg');

        Classification::of('A product photo uploaded by a customer.', [
            'front' => Image::fromPath(__DIR__ . '/../../../Fixtures/Images/blue.jpg')->withProviderOptions(fn (Lab|string $provider): ?array => $provider === Lab::Gemini ? ['resolution' => 'high'] : null),
            'back' => UploadedFile::fake()->createWithContent('back.heic', 'upload-bytes')->mimeType('image/heic'),
        ])
            ->question('damaged', new Boolean('Is the product damaged?'))
            ->classify(provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === [
            ['type' => 'text', 'text' => 'A product photo uploaded by a customer.'],
            ['type' => 'text', 'text' => 'front:'],
            ['resolution' => 'high', 'type' => 'image', 'data' => base64_encode($jpeg), 'mime_type' => 'image/jpeg'],
            ['type' => 'text', 'text' => 'back:'],
            ['type' => 'image', 'data' => base64_encode('upload-bytes'), 'mime_type' => 'image/heic'],
        ]);
    }

    public function testUploadedGeminiFilesAreSentAsImageBlocksByUri(): void
    {
        Http::fake(['*' => Http::response($this->fakeGeminiDecisionsResponse())]);

        Classification::of('A product photo uploaded by a customer.', [Image::fromId('files/abc123')])
            ->question('damaged', new Boolean('Is the product damaged?'))
            ->classify(provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === [
            ['type' => 'text', 'text' => 'A product photo uploaded by a customer.'],
            ['type' => 'image', 'uri' => 'files/abc123'],
        ]);
    }

    public function testImagesInUnsupportedFormatsAreRejectedBeforeAnyRequest(): void
    {
        Http::fake();

        try {
            Classification::of('Inspect this.', [UploadedFile::fake()->createWithContent('photo.svg', '<svg/>')->mimeType('image/svg+xml')])
                ->question('damaged', new Boolean('Damaged?'))
                ->classify(provider: 'gemini');

            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Gemini decisions only accept JPEG, PNG, GIF, WebP, HEIC, HEIF, BMP, or TIFF images; [image/svg+xml] given.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function testARejectedDecisionsRequestThrowsARequestException(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 400, 'message' => 'Invalid value at questions', 'status' => 'INVALID_ARGUMENT']], 400)]);

        $this->expectException(RequestException::class);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');
    }

    public function testAnOverloadedDecisionsEndpointRaisesAFailoverException(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 503, 'message' => 'The model is overloaded.', 'status' => 'UNAVAILABLE']], 503)]);

        $this->expectException(ProviderOverloadedException::class);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');
    }

    /**
     * Create a Gemini decisions response.
     */
    protected function fakeGeminiDecisionsResponse(): array
    {
        return [
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.96, 'confidence' => 0.96],
                'department' => [
                    'type' => 'choice',
                    'choice' => 'payments',
                    'probabilities' => ['account' => 0.0, 'frontend' => 0.16, 'payments' => 0.84],
                    'confidence' => 0.84,
                ],
                'urgency' => [
                    'type' => 'score',
                    'score' => 1.99,
                    'probabilities' => ['0' => 0.0, '1' => 0.01, '2' => 0.99],
                    'legend' => ['0' => 'Next release', '1' => 'This week', '2' => '{"label":"Blocking revenue"}'],
                    'confidence' => 0.99,
                ],
            ],
            'usage' => ['total_input_tokens' => 312, 'total_cached_tokens' => 256, 'total_output_tokens' => 3, 'total_tokens' => 315],
        ];
    }
}
