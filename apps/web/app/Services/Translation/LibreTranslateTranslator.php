<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Throwable;

class LibreTranslateTranslator implements Translator
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {}

    public function provider(): string
    {
        return 'libretranslate';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
    ): TranslationResult {
        $payload = [
            'q' => $text,
            'source' => $sourceLanguage ?? 'auto',
            'target' => $targetLanguage,
            'format' => 'text',
        ];

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $payload['api_key'] = $this->apiKey;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post(rtrim($this->url, '/').'/translate', $payload)
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            throw new TranslationException('LibreTranslate request failed.', 0, $exception);
        }

        $translated = $response['translatedText'] ?? null;

        if (! is_string($translated)) {
            throw new TranslationException('LibreTranslate returned an unexpected response.');
        }

        $detectedLanguage = $response['detectedLanguage'] ?? null;
        $detected = is_array($detectedLanguage)
            ? ($detectedLanguage['language'] ?? null)
            : null;

        return new TranslationResult(
            text: $translated,
            targetLanguage: $targetLanguage,
            sourceLanguage: is_string($detected) ? strtolower($detected) : $sourceLanguage,
        );
    }
}
