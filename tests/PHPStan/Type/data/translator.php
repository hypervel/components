<?php

namespace Translator;

use Hypervel\Contracts\Translation\Translator as TranslatorContract;
use Hypervel\Support\Facades\Lang;
use Hypervel\Tests\PHPStan\Fixtures\Type\StringLang;
use Hypervel\Tests\PHPStan\Fixtures\Type\StringTranslator;
use Hypervel\Translation\Translator;

use function PHPStan\Testing\assertType;

function test(Translator $trans, TranslatorContract $transContract, StringTranslator $stringTranslator): void
{
    assertType('(array|string)', $trans->get('language.string'));
    assertType('(array|string)', $transContract->get('language.string'));
    assertType('(array|string)', Lang::get('language.string'));
    assertType('string', $stringTranslator->get('language.string'));
    assertType('string', StringLang::get('language.string'));
}
