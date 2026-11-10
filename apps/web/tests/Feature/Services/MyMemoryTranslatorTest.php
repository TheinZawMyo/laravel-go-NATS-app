<?php

namespace Tests\Feature\Services;

use App\Services\Translation\MyMemoryTranslator;
use App\Services\Translation\TranslationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MyMemoryTranslatorTest extends TestCase
{
    private function translator(): MyMemoryTranslator
    {
        return new MyMemoryTranslator('https://api.mymemory.translated.net/get', null, 5);
    }

    public function test_it_returns_the_translation_and_detected_language(): void
    {
        Http::fake([
            'api.mymemory.translated.net/*' => Http::response([
                'responseStatus' => 200,
                'responseData' => [
                    'translatedText' => 'Xin chào thế giới',
                    'detectedLanguage' => 'en',
                ],
            ]),
        ]);

        $result = $this->translator()->translate('Hello world', 'vi');

        $this->assertSame('Xin chào thế giới', $result->text);
        $this->assertSame('vi', $result->targetLanguage);
        $this->assertSame('en', $result->sourceLanguage);

        Http::assertSent(fn (Request $request): bool => $request['langpair'] === 'Autodetect|vi'
            && $request['q'] === 'Hello world');
    }

    public function test_long_text_is_split_into_supported_chunks(): void
    {
        Http::fake([
            'api.mymemory.translated.net/*' => Http::response([
                'responseStatus' => 200,
                'responseData' => ['translatedText' => 'ok'],
            ]),
        ]);

        $this->translator()->translate(str_repeat('word ', 150), 'vi');

        Http::assertSentCount(2);

        Http::assertSent(fn (Request $request): bool => mb_strlen((string) $request['q']) <= 500);
    }

    public function test_quota_style_payloads_are_reported_as_provider_failures(): void
    {
        Http::fake([
            'api.mymemory.translated.net/*' => Http::response([
                'responseStatus' => '403',
                'responseData' => [
                    'translatedText' => 'QUERY LENGTH LIMIT EXCEEDED. MAX ALLOWED QUERY : 500 CHARS',
                ],
            ]),
        ]);

        $this->expectException(TranslationException::class);

        $this->translator()->translate('Hello world', 'vi');
    }

    public function test_http_errors_are_reported_as_provider_failures(): void
    {
        Http::fake([
            'api.mymemory.translated.net/*' => Http::response('upstream down', 502),
        ]);

        $this->expectException(TranslationException::class);

        $this->translator()->translate('Hello world', 'vi');
    }
}
