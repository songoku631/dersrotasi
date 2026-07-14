<?php

declare(strict_types=1);

namespace DersRotasi\Import;

final class EducationLanguageNormalizer
{
    private const LANGUAGE_PATTERNS = [
        'İngilizce' => '/(?:%\s*(?:30|100)\s*)?(?:İngilizce|Ingilizce|İngiliz|Ingiliz|English)/ui',
        'Almanca' => '/(?:Almanca|German)/ui',
        'Fransızca' => '/(?:Fransızca|Fransizca|Fransız|Fransiz|French)/ui',
        'Arapça' => '/(?:Arapça|Arapca|Arabic)/ui',
        'Rusça' => '/(?:Rusça|Rusca|Russian)/ui',
        'İspanyolca' => '/(?:İspanyolca|Ispanyolca|Spanish)/ui',
        'İtalyanca' => '/(?:İtalyanca|Italyanca|Italian)/ui',
        'Çince' => '/(?:Çince|Cince|Chinese)/ui',
        'Korece' => '/(?:Korece|Korean)/ui',
    ];

    public static function normalize(string $departmentName, ?string $providedLanguage = null): string
    {
        $fromDepartment = self::languageIn($departmentName);
        if ($fromDepartment !== null) {
            return $fromDepartment;
        }

        $provided = trim((string) $providedLanguage);
        if ($provided !== '') {
            return self::languageIn($provided) ?? $provided;
        }

        return 'Türkçe';
    }

    private static function languageIn(string $value): ?string
    {
        foreach (self::LANGUAGE_PATTERNS as $language => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $language;
            }
        }

        return null;
    }
}
