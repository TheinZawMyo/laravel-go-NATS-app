<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Keyless fallback provider for local development and small deployments.
 *
 * MyMemory is free and anonymous, but each request is capped at 500
 * characters and the daily quota is shared per caller IP. Supplying an email
 * raises the quota, and no message content should be sent here if the chat's
 * privacy requirements forbid a third-party processor; use DeepL or a
 * self-hosted LibreTranslate instead.
 */
class MyMemoryTranslator implements Translator
{
    private const MAX_CHARS = 500;

    public function __construct(
        private readonly string $url,
        private readonly ?string $email,
        private readonly int $timeout,
    ) {}

    public function provider(): string
    {
        return 'mymemory';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
    ): TranslationResult {
        $target = $this->languageCode($targetLanguage);
        $source = $sourceLanguage !== null && $sourceLanguage !== ''
            ? $this->languageCode($sourceLanguage)
            : 'Autodetect';

        $translated = [];
        $detected = null;

        foreach ($this->chunk($text) as $chunk) {
            [$chunkText, $chunkSource] = $this->request($chunk, $source, $target);

            $translated[] = $chunkText;
            $detected ??= $chunkSource;
        }

        return new TranslationResult(
            text: implode(' ', $translated),
            targetLanguage: $targetLanguage,
            sourceLanguage: $detected,
        );
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function request(string $chunk, string $source, string $target): array
    {
        $query = [
            'q' => $chunk,
            'langpair' => $source.'|'.$target,
        ];

        if ($this->email !== null && $this->email !== '') {
            $query['de'] = $this->email;
        }

        try {
            $response = Http::timeout($this->timeout)->get($this->url, $query);
        } catch (Throwable $exception) {
            throw new TranslationException('MyMemory translation request failed.', 0, $exception);
        }

        if ($response->failed()) {
            throw new TranslationException(
                'MyMemory translation request failed with HTTP '.$response->status().'.',
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new TranslationException('MyMemory returned an unexpected response.');
        }

        if (($data['quotaFinished'] ?? false) === true) {
            throw new TranslationException('MyMemory daily quota is exhausted.');
        }

        $text = data_get($data, 'responseData.translatedText');

        if (! is_string($text) || trim($text) === '') {
            throw new TranslationException('MyMemory returned an empty translation.');
        }

        if ($this->isErrorPayload($text, $data)) {
            throw new TranslationException('MyMemory rejected the request: '.$text);
        }

        $detected = data_get($data, 'responseData.detectedLanguage');

        return [
            trim($text),
            is_string($detected) && $detected !== '' ? strtolower($detected) : null,
        ];
    }

    /**
     * The API reports quota and validation problems as a 200 response with an
     * English warning in responseDetails/translatedText.
     *
     * @param  array<mixed, mixed>  $data
     */
    private function isErrorPayload(string $text, array $data): bool
    {
        $status = $data['responseStatus'] ?? null;

        if ($status !== null && (int) $status !== 200) {
            return true;
        }

        $upper = strtoupper($text);
        $details = strtoupper((string) ($data['responseDetails'] ?? ''));

        return str_contains($upper, 'MYMEMORY WARNING')
            || str_contains($upper, 'QUERY LENGTH LIMIT')
            || str_contains($upper, 'INVALID SOURCE LANGUAGE')
            || str_contains($details, 'MYMEMORY WARNING');
    }

    /**
     * Split text into segments the API accepts while keeping word boundaries.
     *
     * @return list<string>
     */
    private function chunk(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (mb_strlen($candidate) <= self::MAX_CHARS) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            while (mb_strlen($word) > self::MAX_CHARS) {
                $chunks[] = mb_substr($word, 0, self::MAX_CHARS);
                $word = mb_substr($word, self::MAX_CHARS);
            }

            $current = $word;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [$text] : $chunks;
    }

    private function languageCode(string $language): string
    {
        return match ($language) {
            'zh' => 'zh-CN',
            'fil' => 'tl',
            'pt' => 'pt-PT',
            default => $language,
        };
    }
}
