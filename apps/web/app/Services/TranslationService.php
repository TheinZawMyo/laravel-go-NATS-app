<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageTranslation;
use App\Services\Translation\TranslationException;
use App\Services\Translation\TranslationUnavailableException;
use App\Services\Translation\Translator;
use Illuminate\Validation\ValidationException;

class TranslationService
{
    public function __construct(private readonly Translator $translator) {}

    /**
     * Translate a message into the requested language and cache the result.
     *
     * Authorization stays with the caller (the message policy); this service
     * only enforces content rules that make server-side translation impossible.
     *
     * @throws ValidationException When the message or target language is not translatable.
     * @throws TranslationUnavailableException When the provider is not configured or fails.
     */
    public function translate(Message $message, string $targetLanguage): MessageTranslation
    {
        $targetLanguage = strtolower($targetLanguage);

        if (! array_key_exists($targetLanguage, (array) config('translation.languages', []))) {
            throw ValidationException::withMessages([
                'language' => __('This translation language is not supported.'),
            ]);
        }

        if ($message->deleted_at !== null) {
            throw ValidationException::withMessages([
                'language' => __('Deleted messages cannot be translated.'),
            ]);
        }

        // Encrypted bodies never reach the server in plaintext, so a
        // server-side provider cannot translate them.
        if ((bool) $message->is_encrypted) {
            throw ValidationException::withMessages([
                'language' => __('End-to-end encrypted messages cannot be translated.'),
            ]);
        }

        $body = trim((string) $message->body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'language' => __('Only messages with text can be translated.'),
            ]);
        }

        $cached = MessageTranslation::query()
            ->where('message_id', $message->id)
            ->where('target_language', $targetLanguage)
            ->first();

        // An edit invalidates any translation computed before it, otherwise the
        // bubble would keep showing text from the previous version.
        $isFresh = $cached !== null
            && ($message->edited_at === null || $cached->created_at >= $message->edited_at);

        if ($isFresh) {
            return $cached;
        }

        try {
            $result = $this->translator->translate($body, $targetLanguage);
        } catch (TranslationException $exception) {
            throw new TranslationUnavailableException(
                __('Translation is unavailable right now. Please try again later.'),
                0,
                $exception,
            );
        }

        return MessageTranslation::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'target_language' => $targetLanguage,
            ],
            [
                'source_language' => $result->sourceLanguage,
                'translated_body' => $result->text,
                'provider' => $this->translator->provider(),
            ],
        );
    }
}
