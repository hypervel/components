<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Classification;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Support\Collection;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

enum CollectionDecidePriority
{
    case Low;
    case High;
}

enum CollectionDecideChannel: string
{
    case Email = 'email';
    case Phone = 'phone';
}

enum CollectionDecideLevel: int
{
    case Minor = 1;
    case Major = 2;
}

function collectionDecideAnswer(string $choice, array $probabilities): array
{
    return ['decision' => new ChoiceAnswer($choice, $probabilities, max($probabilities))];
}

class CollectionDecideMacroTest extends TestCase
{
    public function testAListOfStringsDecidesBetweenItsValues(): void
    {
        Classification::fake([collectionDecideAnswer('refunds', ['refunds' => 0.9, 'marketing' => 0.05, 'sales' => 0.05])]);

        $department = collect(['refunds', 'marketing', 'sales'])
            ->decide('Which department should this be routed to?', 'I want my money back.');

        $this->assertSame('refunds', $department);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('I want my money back.')
            && $prompt->questions['decision']->instructions === 'Which department should this be routed to?'
            && $prompt->questions['decision']->options === ['refunds' => null, 'marketing' => null, 'sales' => null]);
    }

    public function testAStringKeyedCollectionOfStringsUsesItsValuesAsDescriptionsAndReturnsTheKey(): void
    {
        Classification::fake([collectionDecideAnswer('sales', ['refunds' => 0.2, 'sales' => 0.8])]);

        $department = collect([
            'refunds' => 'Customer wants money back',
            'sales' => 'Pricing, quotes, upgrades',
        ])->decide('Where should this go?', 'Can I get a quote?');

        $this->assertSame('sales', $department);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options === [
            'refunds' => 'Customer wants money back',
            'sales' => 'Pricing, quotes, upgrades',
        ]);
    }

    public function testAStringKeyedCollectionIsTreatedAsItemsOnceAResolverIsGiven(): void
    {
        Classification::fake([collectionDecideAnswer('SALES', ['REFUNDS' => 0.1, 'SALES' => 0.9])]);

        $department = collect(['a' => 'refunds', 'b' => 'sales'])
            ->decide('Where should this go?', 'Can I get a quote?', by: fn (string $item): string => strtoupper($item));

        $this->assertSame('sales', $department);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options === ['REFUNDS' => null, 'SALES' => null]);
    }

    public function testStringableNamesAreCastToStrings(): void
    {
        Classification::fake([collectionDecideAnswer('sales', ['refunds' => 0.1, 'sales' => 0.9])]);

        $sales = str('sales');

        $this->assertSame($sales, collect([str('refunds'), $sales])->decide('Where should this go?', 'Can I get a quote?'));
    }

    public function testItemsAreLabeledByAFieldAndDescribedByAField(): void
    {
        Classification::fake([collectionDecideAnswer('Billing', ['Support' => 0.3, 'Billing' => 0.7])]);

        $support = (object) ['name' => 'Support', 'description' => 'Technical problems'];
        $billing = (object) ['name' => 'Billing', 'description' => 'Invoices and payments'];

        $department = collect([$support, $billing])->decide(
            'Which department should handle this?',
            'My invoice is wrong.',
            by: 'name',
            describe: 'description',
        );

        $this->assertSame($billing, $department);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options === [
            'Support' => 'Technical problems',
            'Billing' => 'Invoices and payments',
        ]);
    }

    public function testItemsMayBeLabeledAndDescribedByClosuresOrDescribedBySeveralFields(): void
    {
        Classification::fake([
            collectionDecideAnswer('support', ['support' => 0.6, 'billing' => 0.4]),
            collectionDecideAnswer('billing', ['support' => 0.4, 'billing' => 0.6]),
        ]);

        $departments = collect([
            ['slug' => 'support', 'description' => 'Technical problems', 'topics' => 'bugs, outages'],
            ['slug' => 'billing', 'description' => 'Invoices and payments', 'topics' => 'refunds, invoices'],
        ]);

        $department = $departments->decide(
            'Which department should handle this?',
            'The site is down.',
            by: fn (array $department): string => $department['slug'],
            describe: fn (array $department): string => "{$department['description']} Topics: {$department['topics']}.",
        );

        $this->assertSame('support', $department['slug']);

        $department = $departments->decide('Which department?', 'Refund please.', by: 'slug', describe: ['description', 'topics']);

        $this->assertSame('billing', $department['slug']);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options['support'] === 'Technical problems Topics: bugs, outages.');

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options['billing'] === [
            'description' => 'Invoices and payments',
            'topics' => 'refunds, invoices',
        ]);
    }

    public function testEnumCasesAreLabeledAutomaticallyAndTheCaseIsReturned(): void
    {
        Classification::fake([
            collectionDecideAnswer('High', ['Low' => 0.1, 'High' => 0.9]),
            collectionDecideAnswer('phone', ['email' => 0.2, 'phone' => 0.8]),
            collectionDecideAnswer('Major', ['Minor' => 0.3, 'Major' => 0.7]),
        ]);

        $this->assertSame(CollectionDecidePriority::High, collect(CollectionDecidePriority::cases())->decide('How urgent is this?', 'Production is down!'));
        $this->assertSame(CollectionDecideChannel::Phone, collect(CollectionDecideChannel::cases())->decide('How should we reply?', 'Call me back.'));
        $this->assertSame(CollectionDecideLevel::Major, collect(CollectionDecideLevel::cases())->decide('How severe is this?', 'Data loss.'));
    }

    public function testNullIsReturnedWhenTheChosenOptionFallsBelowTheThreshold(): void
    {
        Classification::fake([
            collectionDecideAnswer('sales', ['refunds' => 0.45, 'sales' => 0.55]),
            collectionDecideAnswer('sales', ['refunds' => 0.45, 'sales' => 0.55]),
        ]);

        $departments = collect(['refunds', 'sales']);

        $this->assertNull($departments->decide('Where should this go?', 'Hmm.', threshold: 0.6));
        $this->assertSame('sales', $departments->decide('Where should this go?', 'Hmm.', threshold: 0.5));
    }

    public function testTheTextMayBeStructured(): void
    {
        Classification::fake([collectionDecideAnswer('sales', ['refunds' => 0.1, 'sales' => 0.9])]);

        collect(['refunds', 'sales'])->decide('Where should this go?', ['subject' => 'Upgrade', 'body' => 'Need more seats.']);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Need more seats.'));
    }

    public function testTheMacroPassesThroughProviderOptions(): void
    {
        Classification::fake();

        collect(['refunds', 'sales'])->decide('Where should this go?', 'Some text.', provider: Lab::TypeSafe, model: 'custom-model', timeout: 45);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->model === 'custom-model'
            && $prompt->timeout === 45);
    }

    public function testItemsWithoutAResolvableNameRequireAByFieldOrClosure(): void
    {
        Classification::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine an option name');

        collect([(object) ['name' => 'Support'], (object) ['name' => 'Billing']])->decide('Which?', 'Text.');
    }

    public function testItemsResolvingToTheSameNameAreRejected(): void
    {
        Classification::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple items resolve to the option name [Support].');

        collect([['name' => 'Support'], ['name' => 'Support']])->decide('Which?', 'Text.', by: 'name');
    }

    public function testAtLeastTwoItemsAreRequired(): void
    {
        Classification::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least two options');

        (new Collection(['refunds']))->decide('Which?', 'Text.');
    }
}
