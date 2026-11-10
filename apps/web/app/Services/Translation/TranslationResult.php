<?php

namespace App\Services\Translation;

final readonly class TranslationResult
{
    public function __construct(
        public string $text,
        public string $targetLanguage,
        public ?string $sourceLanguage = null,
    ) {}
}
