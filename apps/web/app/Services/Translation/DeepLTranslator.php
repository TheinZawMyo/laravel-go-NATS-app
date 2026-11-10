<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Throwable;

class DeepLTranslator implements Translator
{
    public function __construct(
        private readonly ?string $key,
        private readonly ?string $endpoint,
        private readonly int $timeout,
    ) {}

    public function provider(): string
    {
        return 'deepl';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
    ): TranslationResult {
        if ($this->key === null || $this->key === '') {
            throw new TranslationException('DeepL API key is not configured.');
        }

        $payload = [
            'text' => $text,
            'target_lang' => $this->languageCode($targetLanguage),
        ];

        if ($sourceLanguage !== null && $sourceLanguage !== '') {
            $payload['source_lang'] = $this->languageCode($sourceLanguage);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'DeepL-Auth-Key '.$this->key,
            ])
                ->asJson()
                ->timeout($this->timeout)
                ->post($this->endpoint ?? $this->defaultEndpoint(), $payload)
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            throw new TranslationException('DeepL translation request failed.', 0, $exception);
        }

        $translation = is_array($response) ? ($response['translations'][0] ?? null) : null;

        if (! is_array($translation) || ! is_string($translation['text'] ?? null)) {
            throw new TranslationException('DeepL returned an unexpected response.');
        }

        return new TranslationResult(
            text: $translation['text'],
            targetLanguage: $targetLanguage,
            sourceLanguage: is_string($translation['detected_source_language'] ?? null)
                ? strtolower($translation['detected_source_language'])
                : $sourceLanguage,
        );
    }

    private function defaultEndpoint(): string
    {
        return str_ends_with((string) $this->key, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';
    }

    private function languageCode(string $language): string
    {
        return match ($language) {
            'zh' => 'ZH',
            'pt' => 'PT-PT',
            'en' => 'EN-US',
            default => strtoupper($language),
        };
    }
}
