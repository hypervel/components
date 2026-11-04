<?php

declare(strict_types=1);

use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\CollectionChoice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Files\Image;
use Hypervel\Support\Collection;

use function PHPStan\Testing\assertType;

assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of('Inspect this.'));
assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of(['subject' => 'Broken lamp']));
assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of(['first message', 'second message']));
assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of('Two product photos.', [
    'front' => Image::fromBase64('cGhvdG8=', 'image/png'),
    Image::fromBase64('cGhvdG8=', 'image/png'),
]));

assertType('Hypervel\Ai\Classification\Boolean', new Boolean(['Is this urgent?', 'Consider any deadline.'], [
    'true' => ['Money is being lost.', 'A customer is blocked.'],
    'false' => 'A question only.',
]));
assertType('Hypervel\Ai\Classification\Choice', new Choice(['Which team should handle this?', 'Use the evidence.'], [
    'billing' => ['Invoices.', 'Refunds.'],
    'technical' => 'Bugs and outages.',
]));
assertType('Hypervel\Ai\Classification\Score', new Score(['How frustrated is the customer?', 'Judge the tone.'], [
    ['Calm.', 'Polite tone.'],
    'Annoyed',
    ['label' => 'Furious', 'description' => ['Threatens to leave.', 'Uses capitals.']],
]));
assertType('mixed', (new CollectionChoice(new Collection(['billing', 'technical'])))->decide(
    'Which team should handle this?',
    ['first message', 'second message'],
));
