<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Closure;
use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ClassificationTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    /**
     * Create a decisions response.
     */
    protected function fakeDecisionsResponse(): array
    {
        return [
            'id' => 'dec_123',
            'model' => 'typesafe/jev-1.13',
            'provider' => 'TypeSafe',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.96],
                'department' => [
                    'type' => 'choice',
                    'choice' => 'payments',
                    'probabilities' => ['account' => 0.0, 'frontend' => 0.16, 'payments' => 0.84],
                    'confidence' => 0.75,
                ],
                'urgency' => [
                    'type' => 'score',
                    'score' => 1.99,
                    'probabilities' => ['0' => 0.0, '1' => 0.01, '2' => 0.99],
                    'legend' => ['0' => 'Next release', '1' => 'This week', '2' => 'Blocking revenue'],
                    'confidence' => 0.99,
                ],
            ],
            'usage' => ['input_tokens' => 312, 'output_tokens' => 48, 'cost' => 0.000013],
        ];
    }

    public function testClassificationPostsToTheDecisionsEndpointInTheSystemOneWireFormat(): void
    {
        Http::fake(['*' => Http::response($this->fakeDecisionsResponse())]);

        Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
            ->questions([
                'is_urgent' => new Boolean('Does this message convey urgency?'),
                'department' => new Choice('Which team should own this ticket?', [
                    'account' => 'Login, permissions, or profile issues.',
                    'frontend' => 'Rendering or layout issues.',
                    'payments' => null,
                ]),
                'urgency' => new Score('How urgent is this ticket?', [
                    'Next release', 'This week', 'Blocking revenue',
                ]),
            ])
            ->classify(provider: 'openrouter', model: 'typesafe/jev-1.13');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://openrouter.ai/api/alpha/decisions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $body['model'] === 'typesafe/jev-1.13'
                && $body['state'] === 'Stripe connect keeps failing, losing sales, help ASAP'
                && $body['questions'] === [
                    'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this message convey urgency?'],
                    'department' => [
                        'type' => 'choice',
                        'instructions' => 'Which team should own this ticket?',
                        'criteria' => ['account' => 'Login, permissions, or profile issues.', 'frontend' => 'Rendering or layout issues.', 'payments' => null],
                    ],
                    'urgency' => [
                        'type' => 'score',
                        'instructions' => 'How urgent is this ticket?',
                        'criteria' => ['Next release', 'This week', 'Blocking revenue'],
                    ],
                ];
        });
    }

    public function testDecisionsResponseIsParsedIntoTypedAnswers(): void
    {
        Http::fake(['*' => Http::response($this->fakeDecisionsResponse())]);

        $response = Classification::of('text')
            ->questions([
                'is_urgent' => new Boolean('Urgent?'),
                'department' => new Choice('Team?', ['account' => null, 'frontend' => null, 'payments' => null]),
                'urgency' => new Score('How urgent?', ['Next release', 'This week', 'Blocking revenue']),
            ])
            ->classify(provider: 'openrouter');

        $this->assertCount(3, $response);
        $this->assertInstanceOf(BooleanAnswer::class, $response['is_urgent']);
        $this->assertSame(0.96, $response['is_urgent']->probability);
        $this->assertInstanceOf(ChoiceAnswer::class, $response['department']);
        $this->assertSame('payments', $response['department']->choice);
        $this->assertSame(0.16, $response['department']->probabilityOf('frontend'));
        $this->assertInstanceOf(ScoreAnswer::class, $response['urgency']);
        $this->assertSame(1.99, $response['urgency']->score);
        $this->assertSame(2, $response['urgency']->level());
        $this->assertSame('Blocking revenue', $response['urgency']->label());
        $this->assertSame(312, $response->usage->inputTokens);
        $this->assertSame('openrouter', $response->meta->provider);
        $this->assertSame('typesafe/jev-1.13', $response->meta->model);
    }

    public function testClassificationUsesTheDefaultDecisionsModelWhenNoneIsSpecified(): void
    {
        Http::fake(['*' => Http::response($this->fakeDecisionsResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === '~typesafe/jev-latest');
    }

    public function testDecisionsRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.openrouter' => [...config('ai.providers.openrouter'), 'url' => 'http://localhost:8080/api/v1']]);

        Http::fake(['*' => Http::response($this->fakeDecisionsResponse())]);

        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/api/alpha/decisions');
    }

    public function testAnswersWithoutTheOptionalDistributionFieldsFallBackToTheQuestionsAsked(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'typesafe/jev-1.13',
            'answers' => [
                'department' => ['type' => 'choice', 'choice' => 'payments'],
                'urgency' => ['type' => 'score', 'score' => 2.0],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ])]);

        $response = Classification::of('text')
            ->questions([
                'department' => new Choice('Team?', ['account' => null, 'payments' => null]),
                'urgency' => new Score('How urgent?', ['Next release', 'This week', 'Blocking revenue']),
            ])
            ->classify(provider: 'openrouter');

        $this->assertSame([], $response['department']->probabilities);
        $this->assertSame(0.0, $response['department']->probabilityOf('payments'));
        $this->assertNull($response['department']->confidence);
        $this->assertSame([], $response['urgency']->probabilities);
        $this->assertSame(['Next release', 'This week', 'Blocking revenue'], $response['urgency']->legend);
        $this->assertSame('Blocking revenue', $response['urgency']->label());
        $this->assertSame(1.0, $response['urgency']->normalized());
    }

    public function testImageAttachmentsAreAppendedToTheStateAsDataUrlImagePartsLabeledByName(): void
    {
        Http::fake(['*' => Http::response($this->fakeDecisionsResponse())]);

        $jpeg = file_get_contents(__DIR__ . '/../../../Fixtures/Images/blue.jpg');

        Classification::of('A product photo uploaded by a customer.', [
            'front' => Image::fromPath(__DIR__ . '/../../../Fixtures/Images/blue.jpg')->withProviderOptions(fn ($provider) => $provider === Lab::OpenRouter ? ['custom' => 'value'] : null),
            'back' => UploadedFile::fake()->createWithContent('back.png', 'upload-bytes')->mimeType('image/png'),
        ])
            ->question('damaged', new Boolean('Is the product damaged?'))
            ->classify(provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/alpha/decisions'
            && ! $request->hasHeader('TypeSafe-Danger-Zone-Images')
            && json_decode($request->body(), true)['state'] === [
                'A product photo uploaded by a customer.',
                'front:',
                ['custom' => 'value', 'type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
                'back:',
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('upload-bytes')]],
            ]);
    }

    #[DataProvider('statesWithImages')]
    public function testImagePartsFollowTheItemsOfAListStateAndTheObjectOfAStructuredState(array $state, array $expected): void
    {
        Http::fake(['*' => Http::response($this->fakeDecisionsResponse())]);

        Classification::of($state, [Image::fromBase64(base64_encode('photo'), 'image/png')])
            ->question('damaged', new Boolean('Damaged?'))
            ->classify(provider: 'openrouter');

        $part = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('photo')]];

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['state'] === [...$expected, $part]);
    }

    /**
     * Provide list and structured states with the state items that precede the image parts.
     */
    public static function statesWithImages(): array
    {
        return [
            'list' => [['first message', 'second message'], ['first message', 'second message']],
            'object' => [['subject' => 'Broken lamp'], [['subject' => 'Broken lamp']]],
        ];
    }

    public function testWebpImagesAreSentAsWebpDataUrls(): void
    {
        Http::fake(['*' => Http::response($this->fakeDecisionsResponse())]);

        Classification::of('Inspect this.', [UploadedFile::fake()->createWithContent('photo.webp', 'webp-bytes')->mimeType('image/webp')])
            ->question('damaged', new Boolean('Damaged?'))
            ->classify(provider: 'openrouter', model: 'cloudflare/clef-flash');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['state'] === [
            'Inspect this.',
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/webp;base64,' . base64_encode('webp-bytes')]],
        ]);
    }

    #[DataProvider('unsupportedAttachments')]
    public function testAttachmentsThatAreNotInlineJpegPngOrWebpImagesAreRejectedBeforeAnyRequest(Closure $attachment, string $message): void
    {
        Http::fake();

        $exception = null;

        try {
            Classification::of('Inspect this.', [$attachment()])
                ->question('damaged', new Boolean('Damaged?'))
                ->classify(provider: 'openrouter');
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertSame($message, $exception->getMessage());

        Http::assertNothingSent();
    }

    /**
     * Provide attachments the OpenRouter decisions endpoint does not accept.
     */
    public static function unsupportedAttachments(): array
    {
        return [
            'provider image' => [fn () => Image::fromId('file_123'), 'OpenRouter decisions only accept images with inline content; [Hypervel\Ai\Files\ProviderImage] given.'],
            'gif upload' => [fn () => UploadedFile::fake()->createWithContent('photo.gif', 'gif-bytes')->mimeType('image/gif'), 'OpenRouter decisions only accept JPEG, PNG, or WebP images; [image/gif] given.'],
        ];
    }
}
