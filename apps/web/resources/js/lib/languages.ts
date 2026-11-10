/**
 * Kept in sync with config/translation.php. The server validates the same
 * codes, so adding a language here only works after the config accepts it.
 */
export type TranslationLanguage = {
    code: string;
    name: string;
};

export const translationLanguages: TranslationLanguage[] = [
    { code: 'en', name: 'English' },
    { code: 'my', name: 'Burmese' },
    { code: 'th', name: 'Thai' },
    { code: 'zh', name: 'Chinese' },
    { code: 'ja', name: 'Japanese' },
    { code: 'ko', name: 'Korean' },
    { code: 'hi', name: 'Hindi' },
    { code: 'vi', name: 'Vietnamese' },
    { code: 'id', name: 'Indonesian' },
    { code: 'ms', name: 'Malay' },
    { code: 'fil', name: 'Filipino' },
    { code: 'fr', name: 'French' },
    { code: 'de', name: 'German' },
    { code: 'es', name: 'Spanish' },
    { code: 'pt', name: 'Portuguese' },
    { code: 'it', name: 'Italian' },
    { code: 'ru', name: 'Russian' },
    { code: 'uk', name: 'Ukrainian' },
    { code: 'pl', name: 'Polish' },
    { code: 'nl', name: 'Dutch' },
    { code: 'sv', name: 'Swedish' },
    { code: 'tr', name: 'Turkish' },
    { code: 'ar', name: 'Arabic' },
    { code: 'fa', name: 'Persian' },
    { code: 'he', name: 'Hebrew' },
];

export function languageName(code: string | null | undefined): string {
    if (code === null || code === undefined) {
        return '';
    }

    return (
        translationLanguages.find((language) => language.code === code)?.name ??
        code.toUpperCase()
    );
}
