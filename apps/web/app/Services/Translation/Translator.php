<?php

namespace App\Services\Translation;

interface Translator
{
    /**
     * Provider identifier stored with each cached translation.
     */
    public function provider(): string;

    /**
     * Translate plain text into the given application language code.
     *
     * @throws TranslationException When the provider is unavailable or rejects the request.
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
    ): TranslationResult;
}
