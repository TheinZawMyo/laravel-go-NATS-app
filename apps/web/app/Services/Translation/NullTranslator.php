<?php

namespace App\Services\Translation;

class NullTranslator implements Translator
{
    public function provider(): string
    {
        return 'null';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
    ): TranslationResult {
        throw new TranslationException;
    }
}
