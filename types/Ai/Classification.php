<?php

declare(strict_types=1);

use Hypervel\Ai\Classification;
use Hypervel\Ai\Files\Image;

use function PHPStan\Testing\assertType;

assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of('Inspect this.'));
assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of(['subject' => 'Broken lamp']));
assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of(['first message', 'second message']));
assertType('Hypervel\Ai\PendingResponses\PendingClassification', Classification::of('Two product photos.', [
    'front' => Image::fromBase64('cGhvdG8=', 'image/png'),
    Image::fromBase64('cGhvdG8=', 'image/png'),
]));
