<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Translation Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "deepl", "libretranslate", "mymemory", "null". The null
    | driver keeps the feature visible in the UI but reports that translation
    | is unavailable until an operator configures a provider.
    |
    | MyMemory needs no API key and is the quickest way to try the feature, but
    | it forwards message text to a third-party service and caps each request
    | at 500 characters. Choose DeepL or a self-hosted LibreTranslate when chat
    | content must stay with trusted processors.
    |
    */

    'provider' => env('TRANSLATION_PROVIDER', 'null'),

    'timeout' => (int) env('TRANSLATION_TIMEOUT', 8),

    'deepl' => [
        'key' => env('DEEPL_API_KEY'),
        // Free keys end in ":fx" and must use the free API host.
        'endpoint' => env('DEEPL_ENDPOINT'),
    ],

    'libretranslate' => [
        'url' => env('LIBRETRANSLATE_URL', 'http://127.0.0.1:5000'),
        'api_key' => env('LIBRETRANSLATE_API_KEY'),
    ],

    'mymemory' => [
        'url' => env('MYMEMORY_URL', 'https://api.mymemory.translated.net/get'),
        // Optional: raises the anonymous daily quota from 5k to 50k chars.
        'email' => env('MYMEMORY_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    |
    | Codes are lowercase ISO 639-1 and are used as the stored target language.
    | The frontend picker duplicates this list in resources/js/lib/languages.ts;
    | keep both in sync when adding a language.
    |
    */

    'languages' => [
        'en' => 'English',
        'my' => 'Burmese',
        'th' => 'Thai',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'hi' => 'Hindi',
        'vi' => 'Vietnamese',
        'id' => 'Indonesian',
        'ms' => 'Malay',
        'fil' => 'Filipino',
        'fr' => 'French',
        'de' => 'German',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'it' => 'Italian',
        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'pl' => 'Polish',
        'nl' => 'Dutch',
        'sv' => 'Swedish',
        'tr' => 'Turkish',
        'ar' => 'Arabic',
        'fa' => 'Persian',
        'he' => 'Hebrew',
    ],

];
